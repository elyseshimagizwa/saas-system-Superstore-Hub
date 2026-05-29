<?php
require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

/* =========================
   ACCÈS AUTORISÉ
   admin + caissier uniquement
========================= */
$user = currentUser();

$allowedRoles = ['admin', 'caissier'];

if (!in_array($user['role'], $allowedRoles)) {
    header("Location: index.php?error=access_denied");
    exit;
}

$isAdmin = ($user['role'] === 'admin');

$settings = getSettings();

$devise = $settings['devise'] ?? 'FCFA';
$tvaRate = (float)($settings['tva'] ?? 0);

/* =========================
   FILTRES
========================= */

$search = $_GET['search'] ?? '';
$date1  = $_GET['date1'] ?? '';
$date2  = $_GET['date2'] ?? '';
$userId = $_GET['user'] ?? '';

$where = [];
$params = [];

/* SEARCH */
if($search){

    $where[] = "(v.id LIKE ?)";
    $params[] = "%$search%";
}

/* DATE */
if($date1){

    $where[] = "DATE(v.date_vente) >= ?";
    $params[] = $date1;
}

if($date2){

    $where[] = "DATE(v.date_vente) <= ?";
    $params[] = $date2;
}

/* USER */
if($userId){

    $where[] = "v.utilisateur_id=?";
    $params[] = $userId;
}

/* =========================
   SQL
========================= */

$sql = "
SELECT
    v.*,
    u.nom

FROM ventes v

LEFT JOIN utilisateurs u
ON u.id = v.utilisateur_id
";

/* WHERE */
if($where){

    $sql .= " WHERE ".implode(" AND ", $where);
}

/* ORDER */
$sql .= "
ORDER BY v.id DESC
LIMIT 200
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$ventes = $stmt->fetchAll();

/* USERS */
$users = $pdo->query("
    SELECT id, nom
    FROM utilisateurs
    ORDER BY nom ASC
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<!-- HEADER -->
<div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 gap-4">

    <div>

        <h1 class="text-3xl font-bold">
            🧾 Historique des ventes
        </h1>

        <p class="text-gray-500 dark:text-gray-400">
            Gestion complète des tickets POS
        </p>

    </div>

    <div class="flex gap-3 flex-wrap">

        <div class="bg-white dark:bg-slate-800 shadow rounded-2xl p-4 min-w-[140px]">

            <div class="text-sm text-gray-500 dark:text-gray-400">
                Total ventes
            </div>

            <div class="text-2xl font-bold text-green-600">
                <?= count($ventes) ?>
            </div>

        </div>

        <div class="bg-white dark:bg-slate-800 shadow rounded-2xl p-4 min-w-[160px]">

            <div class="text-sm text-gray-500 dark:text-gray-400">
                TVA système
            </div>

            <div class="text-2xl font-bold text-blue-600">
                <?= $tvaRate ?>%
            </div>

        </div>

    </div>

</div>

<!-- FILTRES -->
<div class="bg-white dark:bg-slate-800 p-4 rounded-2xl shadow mb-6">

<form method="GET"
class="grid md:grid-cols-5 gap-4">

    <input
        type="text"
        name="search"
        value="<?= e($search) ?>"
        placeholder="🔎 Ticket ID"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <input
        type="date"
        name="date1"
        value="<?= e($date1) ?>"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <input
        type="date"
        name="date2"
        value="<?= e($date2) ?>"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

    <select
        name="user"
        class="border dark:border-slate-700
        dark:bg-slate-900 rounded-xl p-3"
    >

        <option value="">
            Tous utilisateurs
        </option>

        <?php foreach($users as $u): ?>

        <option
            value="<?= $u['id'] ?>"
            <?= $userId==$u['id'] ? 'selected':'' ?>
        >
            <?= e($u['nom']) ?>
        </option>

        <?php endforeach; ?>

    </select>

    <button
        class="bg-blue-600 hover:bg-blue-700
        text-white rounded-xl font-bold"
    >
        🔍 Filtrer
    </button>

</form>

</div>

<!-- TABLE -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow overflow-hidden">

<div class="overflow-auto">

<table class="w-full">

<thead class="bg-slate-100 dark:bg-slate-900">

<tr>

    <th class="p-4 text-left">#</th>
    <th class="p-4 text-left">Caissier</th>
    <th class="p-4 text-left">Montant TTC</th>
    <th class="p-4 text-left">TVA</th>
    <th class="p-4 text-left">Paiement</th>
    <th class="p-4 text-left">Date</th>
    <th class="p-4 text-left">Actions</th>

</tr>

</thead>

<tbody id="salesTable">

<?php foreach($ventes as $v): ?>

<?php

$total = (float)$v['total'];

$tva = isset($v['tva'])
    ? (float)$v['tva']
    : ($total * $tvaRate / 100);

?>

<tr class="border-b dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition">

    <td class="p-4 font-bold">
        #<?= $v['id'] ?>
    </td>

    <td class="p-4">
        <?= e($v['nom']) ?>
    </td>

    <td class="p-4 text-green-600 font-bold">
        <?= number_format($total,2) ?> <?= $devise ?>
    </td>

    <td class="p-4 text-blue-600 font-semibold">
        <?= number_format($tva,2) ?> <?= $devise ?>
    </td>

    <td class="p-4">
        <span class="px-3 py-1 rounded-full text-sm bg-slate-200 dark:bg-slate-700">
            <?= e($v['mode_paiement']) ?>
        </span>
    </td>

    <td class="p-4">
        <?= e($v['date_vente']) ?>
    </td>

    <td class="p-4">

        <div class="flex gap-2 flex-wrap">

            <a href="ticket_pdf.php?id=<?= $v['id'] ?>"
               target="_blank"
               class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm">

                🖨 Ticket

            </a>

            <button
                onclick="showDetails(<?= $v['id'] ?>)"
                class="bg-slate-700 hover:bg-slate-800 text-white px-3 py-2 rounded-lg text-sm"
            >
                👁 Détails
            </button>

            <a href="ticket_pdf.php?id=<?= $v['id'] ?>"
               target="_blank"
               class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm">

                🔁 Réimprimer

            </a>

            <?php if($isAdmin): ?>

            <a href="annuler_vente.php?id=<?= $v['id'] ?>"
               onclick="return confirm('Annuler cette vente ?')"
               class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded-lg text-sm">

                ❌ Annuler

            </a>

            <?php endif; ?>

        </div>

    </td>

</tr>

<?php endforeach; ?>

<?php if(empty($ventes)): ?>

<tr>
    <td colspan="7" class="p-8 text-center text-gray-500">
        Aucune vente trouvée
    </td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<!-- MODAL -->
<div id="modal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50 p-4">

<div class="bg-white dark:bg-slate-800 w-full max-w-3xl rounded-2xl p-6">

    <div class="flex justify-between items-center mb-4">

        <h2 class="text-2xl font-bold">
            🧾 Détails Ticket
        </h2>

        <button onclick="closeModal()"
            class="bg-red-600 hover:bg-red-700 text-white w-10 h-10 rounded-full">
            ✖
        </button>

    </div>

    <div id="modalContent">
        <div class="text-center py-10 text-gray-500">
            Chargement...
        </div>
    </div>

</div>

</div>

</div>

<script>

async function showDetails(id){

    document.getElementById("modal").classList.remove("hidden");
    document.getElementById("modal").classList.add("flex");

    let r = await fetch("vente_details_ajax.php?id="+id);
    document.getElementById("modalContent").innerHTML = await r.text();
}

function closeModal(){
    document.getElementById("modal").classList.add("hidden");
    document.getElementById("modal").classList.remove("flex");
}

</script>

<?php include 'includes/footer.php'; ?>