<?php

require_once 'config.php';

requireLogin();

$user = currentUser();

/* =========================================
   MULTI MAGASIN
========================================= */

$magasin_id =
    $user['magasin_id'] ?? null;

if (!$magasin_id) {

    exit("
    <div style='padding:30px;font-family:Arial'>
        ⛔ Aucun magasin assigné
    </div>
    ");
}

/* =========================================
   MAGASIN
========================================= */

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

/* =========================================
   OUVRIR SESSION
========================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST'

    &&

    isset($_POST['ouvrir'])

) {

    verify_csrf();

    $montant_initial =

        (float)($_POST['montant_initial'] ?? 0);

    /* CHECK SESSION EXISTANTE */

    $check = $pdo->prepare("
        SELECT id
        FROM sessions_caisse
        WHERE utilisateur_id=?
        AND magasin_id=?
        AND statut='ouverte'
        LIMIT 1
    ");

    $check->execute([

        $user['id'],

        $magasin_id
    ]);

    if ($check->fetch()) {

        flash(
            'error',
            '⚠️ Une session est déjà ouverte'
        );

        header('Location: sessions_caisse.php');

        exit;
    }

    /* INSERT */

    $stmt = $pdo->prepare("
        INSERT INTO sessions_caisse
        (
            utilisateur_id,
            magasin_id,
            montant_initial,
            montant_attendu,
            montant_reel,
            date_ouverture,
            statut
        )
        VALUES
        (
            ?,
            ?,
            ?,
            0,
            0,
            NOW(),
            'ouverte'
        )
    ");

    $stmt->execute([

        $user['id'],

        $magasin_id,

        $montant_initial
    ]);

    flash(
        'success',
        '🟢 Session ouverte avec succès'
    );

    header('Location: caisse.php');

    exit;
}

/* =========================================
   FERMER SESSION
========================================= */

if (

    $_SERVER['REQUEST_METHOD'] === 'POST'

    &&

    isset($_POST['fermer'])

) {

    verify_csrf();

    $session_id =
        (int)$_POST['session_id'];

    $montant_reel =
        (float)($_POST['montant_reel'] ?? 0);

    /* SESSION */

    $stmtSession = $pdo->prepare("
        SELECT *
        FROM sessions_caisse
        WHERE id=?
        AND utilisateur_id=?
        AND magasin_id=?
        AND statut='ouverte'
        LIMIT 1
    ");

    $stmtSession->execute([

        $session_id,

        $user['id'],

        $magasin_id
    ]);

    $session =
        $stmtSession->fetch();

    if (!$session) {

        flash(
            'error',
            'Session introuvable'
        );

        header('Location: sessions_caisse.php');

        exit;
    }

    /* TOTAL VENTES */

    $stmtTotal = $pdo->prepare("
        SELECT
            COALESCE(SUM(total),0)
        FROM ventes
        WHERE utilisateur_id=?
        AND magasin_id=?
        AND date_vente >= ?
    ");

    $stmtTotal->execute([

        $user['id'],

        $magasin_id,

        $session['date_ouverture']
    ]);

    $totalVentes =
        (float)$stmtTotal->fetchColumn();

    /* MONTANT ATTENDU */

    $montant_attendu =

        (float)$session['montant_initial']

        +

        $totalVentes;

    /* DIFFERENCE */

    $difference =

        $montant_reel
        -
        $montant_attendu;

    /* UPDATE */

    $stmtUpdate = $pdo->prepare("
        UPDATE sessions_caisse
        SET

            montant_attendu=?,

            montant_reel=?,

            date_fermeture=NOW(),

            statut='fermée'

        WHERE id=?
    ");

    $stmtUpdate->execute([

        $montant_attendu,

        $montant_reel,

        $session_id
    ]);

    /* LOG */

    if(function_exists('logAction')){

        logAction(

            "SESSION_CAISSE",

            "Session fermée #".$session_id,

            "SUCCESS"
        );
    }

    /* MESSAGE */

    if($difference > 0){

        flash(
            'success',
            '🟢 Session fermée | Excédent : '
            .number_format($difference,2)
        );

    }elseif($difference < 0){

        flash(
            'error',
            '🔴 Manquant : '
            .number_format(abs($difference),2)
        );

    }else{

        flash(
            'success',
            '🟢 Caisse équilibrée'
        );
    }

    header('Location: sessions_caisse.php');

    exit;
}

/* =========================================
   LISTE SESSIONS
========================================= */

$list = $pdo->prepare("
    SELECT
        sc.*,
        m.nom AS magasin_nom
    FROM sessions_caisse sc

    LEFT JOIN magasins m
    ON m.id = sc.magasin_id

    WHERE sc.magasin_id=?

    ORDER BY sc.id DESC
");

$list->execute([
    $magasin_id
]);

$sessions =
    $list->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';

?>

<script src="https://cdn.tailwindcss.com"></script>

<style>

body{

    background:#f1f5f9;
}

.shopify-card{

    background:white;

    border-radius:24px;

    box-shadow:
    0 10px 30px rgba(0,0,0,.06);
}

.shopify-btn{

    border-radius:18px;

    transition:.2s;
}

.shopify-btn:hover{

    transform:translateY(-2px);
}

.badge-open{

    background:#dcfce7;

    color:#166534;
}

.badge-close{

    background:#e5e7eb;

    color:#374151;
}

</style>

<div class="p-4 md:p-6">

<!-- HEADER -->

<div class="flex flex-col lg:flex-row justify-between gap-4 mb-6">

    <div>

        <h1 class="text-4xl font-black text-slate-800">

            🧾 Sessions de Caisse

        </h1>

        <?php if($magasin): ?>

        <div class="mt-2 text-blue-600 font-bold text-lg">

            🏬
            <?= e($magasin['nom']) ?>

        </div>

        <?php endif; ?>

    </div>

</div>

<!-- ALERTES -->

<?php if($m = flash('success')): ?>

<div class="bg-green-100 text-green-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m = flash('error')): ?>

<div class="bg-red-100 text-red-700 p-4 rounded-2xl mb-5">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- OUVERTURE SESSION -->

<div class="shopify-card p-6 mb-6">

    <h2 class="text-2xl font-black mb-5">

        🟢 Ouvrir Session

    </h2>

    <form
        method="POST"
        class="grid md:grid-cols-2 gap-4"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= csrf_token() ?>"
        >

        <div>

            <label class="block mb-2 font-semibold">

                💵 Montant Initial

            </label>

            <input
                type="number"
                step="0.01"
                name="montant_initial"
                required
                class="w-full border p-4 rounded-2xl"
                placeholder="0.00"
            >

        </div>

        <div class="flex items-end">

            <button
                name="ouvrir"
                class="bg-blue-600 text-white w-full p-4 font-bold rounded-2xl shopify-btn"
            >

                🚀 Ouvrir Session

            </button>

        </div>

    </form>

</div>

<!-- TABLE -->

<div class="shopify-card overflow-x-auto">

<table class="min-w-full text-sm">

<thead class="bg-slate-100">

<tr>

    <th class="p-4 text-left">
        ID
    </th>

    <th class="p-4 text-left">
        Magasin
    </th>

    <th class="p-4 text-left">
        Initial
    </th>

    <th class="p-4 text-left">
        Attendu
    </th>

    <th class="p-4 text-left">
        Réel
    </th>

    <th class="p-4 text-left">
        Statut
    </th>

    <th class="p-4 text-left">
        Ouverture
    </th>

    <th class="p-4 text-left">
        Fermeture
    </th>

    <th class="p-4 text-center">
        Action
    </th>

</tr>

</thead>

<tbody>

<?php foreach($sessions as $s): ?>

<tr class="border-t hover:bg-slate-50">

    <td class="p-4 font-bold">

        #<?= $s['id'] ?>

    </td>

    <td class="p-4">

        <?= e($s['magasin_nom']) ?>

    </td>

    <td class="p-4 font-semibold">

        <?= number_format(
            $s['montant_initial'],
            2
        ) ?>

    </td>

    <td class="p-4 text-green-600 font-bold">

        <?= number_format(
            $s['montant_attendu'],
            2
        ) ?>

    </td>

    <td class="p-4 text-blue-600 font-bold">

        <?= number_format(
            $s['montant_reel'],
            2
        ) ?>

    </td>

    <td class="p-4">

        <span class="
            px-3 py-1 rounded-full text-xs font-bold

            <?= $s['statut']=='ouverte'
                ? 'badge-open'
                : 'badge-close'
            ?>
        ">

            <?= e($s['statut']) ?>

        </span>

    </td>

    <td class="p-4 text-sm">

        <?= e($s['date_ouverture']) ?>

    </td>

    <td class="p-4 text-sm">

        <?= e(
            $s['date_fermeture']
            ??
            '--'
        ) ?>

    </td>

    <td class="p-4 text-center">

        <?php if($s['statut']=='ouverte'): ?>

        <form
            method="POST"
            class="space-y-2"
            onsubmit="return confirm('Fermer cette session ?')"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= csrf_token() ?>"
            >

            <input
                type="hidden"
                name="session_id"
                value="<?= $s['id'] ?>"
            >

            <input
                type="number"
                step="0.01"
                required
                name="montant_reel"
                placeholder="Montant réel"
                class="border p-2 rounded-xl w-40"
            >

            <button
                name="fermer"
                class="bg-red-500 text-white px-4 py-2 rounded-xl shopify-btn"
            >

                🔒 Fermer

            </button>

        </form>

        <?php else: ?>

        <span class="text-gray-400">
            --
        </span>

        <?php endif; ?>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php include 'includes/footer.php'; ?>