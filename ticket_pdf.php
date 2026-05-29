
<?php
require_once 'config.php';
require_once 'config-settings.php';
requireLogin();

/* =========================
   SETTINGS
========================= */

$settings = getSettings();

$devise =
    $settings['devise'];

$tvaRate =
    $settings['tva'];

$id =
    (int)($_GET['id'] ?? 0);

/* =========================
   VENTE
========================= */

$stmt = $pdo->prepare("
    SELECT
        v.*,
        u.nom AS caissier,
        m.nom AS magasin_nom,
        m.adresse AS magasin_adresse,
        m.telephone AS magasin_telephone
    FROM ventes v

    JOIN utilisateurs u
        ON u.id = v.utilisateur_id

    LEFT JOIN magasins m
        ON m.id = v.magasin_id

    WHERE v.id=?
");

$stmt->execute([$id]);

$vente = $stmt->fetch();

if(!$vente){

    exit("❌ Vente introuvable");
}

/* =========================
   LIGNES
========================= */

$stmt = $pdo->prepare("
    SELECT
        p.nom,
        lv.quantite,
        lv.prix_unitaire,
        lv.sous_total

    FROM ligne_ventes lv

    JOIN produits p
        ON p.id = lv.produit_id

    WHERE lv.vente_id=?
");

$stmt->execute([$id]);

$lines = $stmt->fetchAll();

/* =========================
   CALCULS
========================= */

$totalTTC =
    $vente['total'];

$tva =
    $vente['tva']
    ??
    ($totalTTC * $tvaRate / (100 + $tvaRate));

$totalHT =
    $totalTTC - $tva;

/* =========================
   QR CODE
========================= */

$qrText =
    "VENTE #".$vente['id']
    ." | TOTAL : "
    .$totalTTC." ".$devise;
?>

<!DOCTYPE html>

<html lang="fr">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>

Ticket #<?= $vente['id'] ?>

</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>

body{

    background:#e5e7eb;

    font-family:
        monospace;
}

.ticket{

    width:80mm;

    background:white;

    margin:auto;

    padding:14px;
}

.line{

    border-top:
        1px dashed #000;

    margin:
        10px 0;
}

@media print{

    body{

        background:white;
    }

    .no-print{

        display:none;
    }

    .ticket{

        width:80mm;

        box-shadow:none;
    }
}

</style>

</head>

<body class="py-6">

<div class="ticket shadow-2xl rounded-xl">

    <!-- LOGO -->
    <?php if(!empty($settings['logo'])): ?>

    <div class="flex justify-center mb-3">

        <img
            src="<?= e($settings['logo']) ?>"
            class="w-20 h-20 object-contain"
        >

    </div>

    <?php endif; ?>

    <!-- BOUTIQUE -->
    <div class="text-center">

        <div class="text-xl font-black uppercase">

            <?= e($settings['nom_boutique']) ?>

        </div>

        <?php if(!empty($vente['magasin_nom'])): ?>

        <div class="text-sm mt-1">

            🏪
            <?= e($vente['magasin_nom']) ?>

        </div>

        <?php endif; ?>

        <div class="text-xs text-gray-600 mt-2">

            <?= e($vente['magasin_adresse']) ?>

            <br>

            📞
            <?= e($vente['magasin_telephone']) ?>

        </div>

    </div>

    <div class="line"></div>

    <!-- INFOS -->
    <div class="text-xs leading-6">

        <div class="flex justify-between">

            <span>Ticket :</span>

            <span>
                #<?= $vente['id'] ?>
            </span>

        </div>

        <div class="flex justify-between">

            <span>Date :</span>

            <span>
                <?= $vente['date_vente'] ?>
            </span>

        </div>

        <div class="flex justify-between">

            <span>Caissier :</span>

            <span>
                <?= e($vente['caissier']) ?>
            </span>

        </div>

        <div class="flex justify-between">

            <span>Paiement :</span>

            <span>
                <?= e($vente['mode_paiement']) ?>
            </span>

        </div>

    </div>

    <div class="line"></div>

    <!-- PRODUITS -->
    <div>

        <?php foreach($lines as $l): ?>

        <div class="mb-3">

            <div class="font-bold text-sm">

                <?= e($l['nom']) ?>

            </div>

            <div class="flex justify-between text-xs">

                <span>

                    <?= $l['quantite'] ?>

                    x

                    <?= number_format(
                        $l['prix_unitaire'],
                        2
                    ) ?>

                    <?= $devise ?>

                </span>

                <span class="font-bold">

                    <?= number_format(
                        $l['sous_total'],
                        2
                    ) ?>

                    <?= $devise ?>

                </span>

            </div>

        </div>

        <?php endforeach; ?>

    </div>

    <div class="line"></div>

    <!-- TOTAL -->
    <div class="text-sm">

        <div class="flex justify-between">

            <span>HT :</span>

            <span>

                <?= number_format(
                    $totalHT,
                    2
                ) ?>

                <?= $devise ?>

            </span>

        </div>

        <div class="flex justify-between">

            <span>

                TVA
                <?= $tvaRate ?>%

            </span>

            <span>

                <?= number_format(
                    $tva,
                    2
                ) ?>

                <?= $devise ?>

            </span>

        </div>

        <div class="flex justify-between text-lg font-black mt-2">

            <span>TOTAL</span>

            <span>

                <?= number_format(
                    $totalTTC,
                    2
                ) ?>

                <?= $devise ?>

            </span>

        </div>

    </div>

    <div class="line"></div>

    <!-- RECU -->
    <div class="text-xs leading-6">

        <div class="flex justify-between">

            <span>Reçu :</span>

            <span>

                <?= number_format(
                    $vente['montant_recu'],
                    2
                ) ?>

                <?= $devise ?>

            </span>

        </div>

        <div class="flex justify-between">

            <span>Monnaie :</span>

            <span>

                <?= number_format(
                    $vente['monnaie'],
                    2
                ) ?>

                <?= $devise ?>

            </span>

        </div>

    </div>

    <div class="line"></div>

    <!-- QR -->
    <div class="flex justify-center my-3">

        <img
            src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($qrText) ?>"
        >

    </div>

    <!-- FOOTER -->
    <div class="text-center text-xs text-gray-600 leading-6">

        🙏 Merci pour votre achat

        <br>

        À bientôt ❤️

    </div>

</div>

<!-- ACTIONS -->
<div class="no-print mt-5 flex justify-center gap-3">

    <button
        onclick="window.print()"
        class="bg-black text-white px-6 py-3 rounded-xl font-bold"
    >

        🖨 Imprimer

    </button>

    <button
        onclick="window.location='caisse.php'"
        class="bg-blue-600 text-white px-6 py-3 rounded-xl font-bold"
    >

        ⬅ Retour Caisse

    </button>

</div>

<script>

/* =========================
   AUTO PRINT
========================= */

window.onload = ()=>{

    setTimeout(()=>{

        window.print();

    },500);
};

</script>

</body>
</html>

