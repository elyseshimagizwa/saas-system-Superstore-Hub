<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

/* =========================================================
   HEADERS SECURITE
========================================================= */

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* =========================================================
   USER
========================================================= */

$user = currentUser();

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

if ($magasin_id <= 0) {

    http_response_code(403);

    exit("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Aucun magasin assigné
    </div>
    ");
}

/* =========================================================
   SETTINGS
========================================================= */

$settings = getSettings();

$tvaRate =
    (float)($settings['tva'] ?? 0);

$devise =
    trim($settings['devise'] ?? 'BIF');

$nomBoutique =
    trim($settings['nom_boutique'] ?? 'Boutique');

/* =========================================================
   MAGASIN
========================================================= */

$stmtMagasin = $pdo->prepare("
    SELECT *
    FROM magasins
    WHERE id=?
    LIMIT 1
");

$stmtMagasin->execute([
    $magasin_id
]);

$magasin =
    $stmtMagasin->fetch();

if (!$magasin) {

    http_response_code(403);

    exit("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Magasin introuvable
    </div>
    ");
}

/* =========================================================
   SESSION CAISSE
========================================================= */

$stmt = $pdo->prepare("
    SELECT id
    FROM sessions_caisse
    WHERE utilisateur_id=?
    AND statut='ouverte'
    LIMIT 1
");

$stmt->execute([
    (int)$user['id']
]);

$session = $stmt->fetch();

if (!$session) {

    exit("
    <div style='padding:30px;font-family:Arial'>
        🔴 Ouvrez une session de caisse avant de vendre.<br><br>

        <a href='sessions_caisse.php'>
            ➡ Ouvrir Session
        </a>
    </div>
    ");
}

/* =========================================================
   FONCTIONS SECURITE
========================================================= */

function cleanString(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

function secureFloat($value): float
{
    return round((float)$value, 2);
}

function secureInt($value): int
{
    return (int)$value;
}

/* =========================================================
   VENTE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['valider'])
) {

    verify_csrf();

    try {

        $allowedModes = [
            'Espèces',
            'Carte',
            'Mobile Money'
        ];

        $mode =
            cleanString(
                $_POST['mode_paiement'] ?? 'Espèces'
            );

        if (!in_array($mode, $allowedModes, true)) {

            throw new Exception(
                "Mode de paiement invalide"
            );
        }

        $montantRecu =
            secureFloat(
                $_POST['montant_recu'] ?? 0
            );

        if ($montantRecu < 0) {

            throw new Exception(
                "Montant reçu invalide"
            );
        }

        $panierJson =
            $_POST['panier'] ?? '[]';

        if (
            strlen($panierJson)
            > 100000
        ) {

            throw new Exception(
                "Panier trop volumineux"
            );
        }

        $items =
            json_decode(
                $panierJson,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (
            !is_array($items)
            ||
            empty($items)
        ) {

            throw new Exception(
                "Panier vide"
            );
        }

        if (count($items) > 200) {

            throw new Exception(
                "Trop de produits"
            );
        }

        $pdo->beginTransaction();

        $totalHT = 0;

        $validatedItems = [];

        foreach ($items as $it) {

            $produit_id =
                secureInt($it['id'] ?? 0);

            $qty =
                secureInt($it['qty'] ?? 0);

            if (
                $produit_id <= 0
                ||
                $qty <= 0
            ) {

                throw new Exception(
                    "Produit invalide"
                );
            }

            if ($qty > 10000) {

                throw new Exception(
                    "Quantité trop élevée"
                );
            }

            $q = $pdo->prepare("
                SELECT
                    id,
                    nom,
                    prix_vente,
                    quantite,
                    magasin_id
                FROM produits
                WHERE id=?
                AND magasin_id=?
                FOR UPDATE
            ");

            $q->execute([

                $produit_id,

                $magasin_id
            ]);

            $p = $q->fetch();

            if (!$p) {

                throw new Exception(
                    "Produit introuvable"
                );
            }

            $stock =
                secureInt($p['quantite']);

            if ($stock < $qty) {

                throw new Exception(
                    "Stock insuffisant : "
                    . e($p['nom'])
                );
            }

            $prix =
                secureFloat(
                    $p['prix_vente']
                );

            $sousTotal =
                secureFloat(
                    $prix * $qty
                );

            $totalHT +=
                $sousTotal;

            $validatedItems[] = [

                'id' => $produit_id,

                'qty' => $qty,

                'nom' => $p['nom'],

                'prix_vente' => $prix,

                'ancien_stock' => $stock,

                'nouveau_stock' =>
                    $stock - $qty,

                'sous_total' => $sousTotal
            ];
        }

        $tva =
            secureFloat(
                $totalHT *
                ($tvaRate / 100)
            );

        $totalTTC =
            secureFloat(
                $totalHT + $tva
            );

        if (
            $mode === 'Espèces'
            &&
            $montantRecu < $totalTTC
        ) {

            throw new Exception(
                "Montant insuffisant"
            );
        }

        $monnaie =
            secureFloat(
                max(
                    0,
                    $montantRecu - $totalTTC
                )
            );

        $stmt = $pdo->prepare("
            INSERT INTO ventes
            (
                utilisateur_id,
                magasin_id,
                total,
                montant_recu,
                monnaie,
                mode_paiement,
                date_vente,
                tva
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                ?
            )
        ");

        $stmt->execute([

            secureInt($user['id']),

            $magasin_id,

            $totalTTC,

            $montantRecu,

            $monnaie,

            $mode,

            $tva
        ]);

        $venteId =
            secureInt(
                $pdo->lastInsertId()
            );

        foreach ($validatedItems as $item) {

            $updateStock =
                $pdo->prepare("
                    UPDATE produits
                    SET quantite=?
                    WHERE id=?
                    AND magasin_id=?
                ");

            $updateStock->execute([

                $item['nouveau_stock'],

                $item['id'],

                $magasin_id
            ]);

            $ligne =
                $pdo->prepare("
                    INSERT INTO ligne_ventes
                    (
                        vente_id,
                        produit_id,
                        quantite,
                        prix_unitaire,
                        sous_total
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

            $ligne->execute([

                $venteId,

                $item['id'],

                $item['qty'],

                $item['prix_vente'],

                $item['sous_total']
            ]);

            $stockHistory =
                $pdo->prepare("
                    INSERT INTO stock_mouvements
                    (
                        magasin_id,
                        produit_id,
                        type,
                        quantite,
                        ancien_stock,
                        nouveau_stock,
                        motif,
                        utilisateur_id,
                        date_mouvement
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        'sortie',
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");

            $stockHistory->execute([

                $magasin_id,

                $item['id'],

                $item['qty'],

                $item['ancien_stock'],

                $item['nouveau_stock'],

                'Vente caisse',

                secureInt($user['id'])
            ]);

            if (
                $item['nouveau_stock']
                <= 5
            ) {

                $alert =
                    $pdo->prepare("
                        INSERT INTO alertes_stock
                        (
                            produit_id,
                            message,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            NOW()
                        )
                    ");

                $alert->execute([

                    $item['id'],

                    'Stock faible : '
                    .$item['nom']
                ]);
            }
        }

        $historique =
            $pdo->prepare("
                INSERT INTO historiques
                (
                    utilisateur_id,
                    magasin_id,
                    action,
                    details,
                    ip,
                    created_at,
                    niveau
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW(),
                    ?
                )
            ");

        $historique->execute([

            secureInt($user['id']),

            $magasin_id,

            'VENTE',

            'Nouvelle vente #'
            .$venteId
            .' | Total : '
            .$totalTTC
            .' '
            .$devise,

            $_SERVER['REMOTE_ADDR']
            ?? 'UNKNOWN',

            'SUCCESS'
        ]);

        if (function_exists('logAction')) {

            logAction(

                "VENTE",

                "Nouvelle vente #".$venteId,

                "SUCCESS"
            );
        }

        $pdo->commit();

        session_regenerate_id(true);

        header(
            "Location: ticket_pdf.php?id="
            .$venteId
        );

        exit;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();
        }

        flash(
            'error',
            $e->getMessage()
        );

        header("Location: caisse.php");

        exit;
    }
}

/* =========================================================
   PRODUITS
========================================================= */

$stmtProduits = $pdo->prepare("
    SELECT
        id,
        nom,
        prix_vente,
        quantite,
        codebarre,
        magasin_id
    FROM produits
    WHERE quantite > 0
    AND magasin_id=?
    ORDER BY nom ASC
");

$stmtProduits->execute([
    $magasin_id
]);

$produits =
    $stmtProduits->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<script src="https://cdn.tailwindcss.com"></script>

<script>

const TVA_RATE =
    <?= json_encode($tvaRate) ?>;

const DEVISE =
    <?= json_encode($devise) ?>;

</script>

<style>

body{
    background:#f3f4f6;
}

.shopify-card{
    background:white;
    border-radius:24px;
    box-shadow:
    0 10px 30px rgba(0,0,0,.06);
}

.product-card{
    background:white;
    border-radius:22px;
    padding:18px;
    transition:.2s;
    border:1px solid #e5e7eb;
}

.product-card:hover{
    transform:translateY(-3px);
    box-shadow:
    0 10px 25px rgba(0,0,0,.08);
}

.product-price{
    color:#16a34a;
    font-weight:bold;
}

.cart-item{
    background:#f9fafb;
    border-radius:16px;
    padding:12px;
    margin-bottom:10px;
}

.pos-btn{
    border-radius:18px;
    transition:.2s;
}

.pos-btn:hover{
    transform:scale(1.02);
}

/* =========================================
   PANIER FLOTTANT
========================================= */

#cartPanel{

    transition:.3s;
}

@media(max-width:768px){

    #cartPanel{

        width:95%;
        right:2.5%;
        top:90px;
    }
}

</style>

<div class="p-4 md:p-6">

<div class="flex flex-col lg:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-4xl font-black text-slate-800">
        
        </h1>

        <div class="mt-2 text-slate-500">
            <h1><?= e($nomBoutique) ?></h1>
        </div>

        <div class="mt-2 text-blue-600 font-bold">
            🏬 <?= e($magasin['nom']) ?>
        </div>

    </div>

    <div class="shopify-card p-5">

        <div class="text-sm text-gray-500">
            Session caisse
        </div>

        <div class="text-green-600 font-bold text-xl">
            🟢 Ouverte
        </div>

    </div>

</div>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- =========================================
     BOUTON PANIER FLOTTANT
========================================= -->

<button
    id="cartToggle"
    onclick="toggleCart()"
    class="fixed top-5 right-5 z-50 bg-black text-white w-16 h-16 rounded-full shadow-2xl flex items-center justify-center text-2xl hover:scale-105 transition"
>

    🛒

    <span
        id="cartCount"
        class="absolute -top-2 -right-2 bg-red-500 text-white text-xs w-6 h-6 rounded-full flex items-center justify-center font-bold hidden"
    >

        0

    </span>

</button>

<div class="grid xl:grid-cols-1 gap-6">

<!-- PRODUITS -->

<div class="shopify-card p-5">

    <div class="grid md:grid-cols-2 gap-4 mb-5">

        <input
            id="search"
            class="border p-4 rounded-2xl"
            placeholder="🔎 Rechercher produit..."
            maxlength="100"
        >

        <video
            id="preview"
            class="w-full rounded-2xl hidden"
        ></video>

    </div>

    <button
        type="button"
        onclick="startScanner()"
        class="bg-black text-white px-5 py-3 rounded-2xl mb-5 pos-btn"
    >

        📷 Scanner Code Barre

    </button>

    <div
        id="productList"
        class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4"
    >

        <?php foreach($produits as $p): ?>

        <button
            type="button"
            onclick='addItem(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
            data-name="<?= strtolower(e($p['nom'])) ?>"
            class="product-card text-left"
        >

            <div class="text-lg font-bold text-slate-800">
                <?= e($p['nom']) ?>
            </div>

            <div class="mt-2 product-price">

                <?= number_format(
                    (float)$p['prix_vente'],
                    2
                ) ?>

                <?= e($devise) ?>

            </div>

            <div class="mt-2 text-sm text-gray-500">

                Stock :
                <?= (int)$p['quantite'] ?>

            </div>

        </button>

        <?php endforeach; ?>

    </div>

</div>

</div>

<!-- =========================================
     PANIER FLOTTANT
========================================= -->

<div
    id="cartPanel"
    class="fixed top-24 right-5 w-[380px] max-w-[95%] bg-white rounded-3xl shadow-2xl border z-40 hidden flex flex-col max-h-[85vh]"
>

<div class="flex items-center justify-between p-5 border-b">

    <h2 class="font-black text-2xl">

        🛒 Panier

    </h2>

    <button
        onclick="toggleCart()"
        class="text-gray-500 hover:text-red-500 text-xl"
    >

        ✖

    </button>

</div>

<div
    id="cart"
    class="flex-1 overflow-y-auto p-5"
></div>

<div class="border-t pt-4 mt-4 p-5">

    <div class="space-y-2 text-lg">

        <div class="flex justify-between">

            <span>HT</span>

            <span id="ht">0</span>

        </div>

        <div class="flex justify-between">

            <span>
                TVA <?= e((string)$tvaRate) ?>%
            </span>

            <span id="tva">0</span>

        </div>

        <div class="flex justify-between text-2xl font-black">

            <span>Total</span>

            <span id="total">
                0
            </span>

        </div>

    </div>

    <form
        method="POST"
        onsubmit="return submitCart()"
        class="mt-5"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= csrf_token() ?>"
        >

        <input
            type="hidden"
            name="valider"
            value="1"
        >

        <input
            type="hidden"
            name="panier"
            id="panierField"
        >

        <select
            name="mode_paiement"
            class="w-full p-4 rounded-2xl border mb-3"
        >

            <option>
                Espèces
            </option>

            <option>
                Carte
            </option>

            <option>
                Mobile Money
            </option>

        </select>

        <input
            id="recu"
            name="montant_recu"
            class="w-full p-4 rounded-2xl border"
            placeholder="Montant reçu"
            type="number"
            min="0"
            step="0.01"
        >

        <div class="mt-4 text-xl font-bold">

            💰 Monnaie :
            <span id="change">0</span>

        </div>

        <button
            class="w-full bg-green-600 text-white p-4 mt-5 rounded-2xl font-bold text-lg pos-btn"
        >

            ✔ Finaliser Vente

        </button>

    </form>

</div>

</div>

<script src="https://unpkg.com/@zxing/library@latest"></script>

<script>

let cart = [];

const produits =
    <?= json_encode($produits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

/* =========================================
   PANIER FLOTTANT
========================================= */

function toggleCart(){

    const panel =
        document.getElementById('cartPanel');

    panel.classList.toggle('hidden');
}

function openCart(){

    document
        .getElementById('cartPanel')
        .classList
        .remove('hidden');
}

function updateCartBadge(){

    let total = 0;

    cart.forEach(i => {

        total += i.qty;
    });

    const badge =
        document.getElementById('cartCount');

    badge.innerText = total;

    if(total > 0){

        badge.classList.remove('hidden');

    }else{

        badge.classList.add('hidden');
    }
}

/* =========================================================
   BEEP
========================================================= */

function beep(){

    new Audio(
        "https://actions.google.com/sounds/v1/alarms/beep_short.ogg"
    ).play();
}

/* =========================================================
   AJOUT PRODUIT
========================================================= */

function addItem(p){

    if(!p || !p.id){
        return;
    }

    let found =
        cart.find(
            i => i.id == p.id
        );

    if(found){

        if(found.qty >= p.quantite){

            alert("Stock maximum atteint");

            return;
        }

        found.qty++;

    }else{

        cart.push({

            id:p.id,
            nom:p.nom,
            prix_vente:parseFloat(p.prix_vente),
            quantite:parseInt(p.quantite),
            qty:1,
            codebarre:p.codebarre
        });
    }

    beep();

    openCart();

    updateCartBadge();

    render();
}

/* =========================================================
   AUGMENTER
========================================================= */

function increaseQty(index){

    if(
        cart[index].qty
        >=
        cart[index].quantite
    ){

        alert("Stock insuffisant");

        return;
    }

    cart[index].qty++;

    render();
}

/* =========================================================
   DIMINUER
========================================================= */

function decreaseQty(index){

    if(cart[index].qty > 1){

        cart[index].qty--;

    }else{

        cart.splice(index,1);
    }

    render();
}

/* =========================================================
   REMOVE
========================================================= */

function removeItem(index){

    cart.splice(index,1);

    updateCartBadge();

    render();
}

/* =========================================================
   SCANNER
========================================================= */

function startScanner(){

    document
        .getElementById('preview')
        .classList
        .remove('hidden');

    const codeReader =
        new ZXing.BrowserBarcodeReader();

    codeReader.decodeFromVideoDevice(

        null,

        'preview',

        (result)=>{

            if(result){

                let p =
                    produits.find(

                        x =>
                        x.codebarre
                        ==
                        result.text
                    );

                if(p){

                    addItem(p);
                }
            }
        }
    );
}

/* =========================================================
   MONNAIE
========================================================= */

function calc(total){

    let r =
        parseFloat(
            document
            .getElementById('recu')
            .value
        ) || 0;

    document
        .getElementById('change')
        .innerText =

        Math.max(
            0,
            r - total
        ).toFixed(2)

        + " "

        + DEVISE;
}

/* =========================================================
   RENDER
========================================================= */

function render(){

    let html = '';

    let ht = 0;

    cart.forEach((i,index)=>{

        let s =
            i.qty *
            i.prix_vente;

        ht += s;

        html += `
        <div class="cart-item">

            <div class="flex justify-between gap-3">

                <div class="flex-1">

                    <div class="font-bold text-lg">

                        ${escapeHtml(i.nom)}

                    </div>

                    <div class="text-sm text-gray-500 mt-1">

                        ${i.prix_vente}
                        ${DEVISE}
                        / unité

                    </div>

                    <div class="flex items-center gap-2 mt-3">

                        <button
                            type="button"
                            onclick="decreaseQty(${index})"
                            class="w-9 h-9 rounded-xl bg-red-500 text-white font-bold text-lg"
                        >

                            -

                        </button>

                        <div class="px-4 py-2 bg-gray-100 rounded-xl font-bold">

                            ${i.qty}

                        </div>

                        <button
                            type="button"
                            onclick="increaseQty(${index})"
                            class="w-9 h-9 rounded-xl bg-green-600 text-white font-bold text-lg"
                        >

                            +

                        </button>

                    </div>

                </div>

                <div class="text-right">

                    <div class="font-black text-lg">

                        ${s.toFixed(2)}
                        ${DEVISE}

                    </div>

                    <button
                        type="button"
                        onclick="removeItem(${index})"
                        class="text-red-500 text-sm mt-2"
                    >

                        🗑 Supprimer

                    </button>

                </div>

            </div>

        </div>
        `;
    });

    let tva =
        ht * (TVA_RATE / 100);

    let total =
        ht + tva;

    document
        .getElementById('cart')
        .innerHTML = html;

    document
        .getElementById('ht')
        .innerText =
        ht.toFixed(2)
        + " "
        + DEVISE;

    document
        .getElementById('tva')
        .innerText =
        tva.toFixed(2)
        + " "
        + DEVISE;

    document
        .getElementById('total')
        .innerText =
        total.toFixed(2)
        + " "
        + DEVISE;

    calc(total);

    updateCartBadge();
}

/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(text){

    const div =
        document.createElement('div');

    div.innerText = text;

    return div.innerHTML;
}

/* =========================================================
   SUBMIT
========================================================= */

function submitCart(){

    if(cart.length === 0){

        alert("Panier vide");

        return false;
    }

    document
        .getElementById('panierField')
        .value =
        JSON.stringify(cart);

    return true;
}

/* =========================================================
   SEARCH
========================================================= */

document
.getElementById('search')
.oninput = function(){

    let v =
        this.value.toLowerCase();

    document
        .querySelectorAll(
            '#productList button'
        )

        .forEach(b=>{

            b.style.display =

                b.dataset.name
                .includes(v)

                ? 'block'

                : 'none';
        });
};

document
.getElementById('recu')

.addEventListener(
    'input',
    ()=>{

        let total =
            parseFloat(

                document
                .getElementById('total')
                .innerText

            ) || 0;

        calc(total);
    }
);

</script>

<?php include 'includes/footer.php'; ?>