<?php
require_once "session.php";
requireRole(['admin', 'manager']);

// Update/Edit Total Stock: deduct the entered amount (Sacks) from the
// single-row `total` ledger, floored at 0. Inventory rows are fixed
// production-tracking records and are never touched here.
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['quantity'])) {
    $entered = (int)($_POST['quantity'] ?? 0);
    $receiver = trim($_POST['description'] ?? '');
    if ($entered <= 0) {
        header("Location: ../inventory.php?error=1");
        exit;
    }

    $stmtTotal = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
    $stmtTotal->execute();
    $current = (int)($stmtTotal->fetchColumn() ?? 0);

    $deduct = min($entered, $current);
    if ($deduct <= 0) {
        header("Location: ../inventory.php?error=1");
        exit;
    }

    $updT = $pdo->prepare("UPDATE total SET total_stock = :total");
    $updT->execute([':total' => $current - $deduct]);

    $hist = $pdo->prepare("INSERT INTO history (user, action, ref_id, product, quantity, unit) VALUES (:user, 'Stock Deducted', :ref, :prod, :quan, :unit)");
    $hist->execute([
        ':user' => $_SESSION['user_name'] ?? '',
        ':ref' => $receiver !== '' ? substr($receiver, 0, 15) : 'TOTAL',
        ':prod' => 'Vermicast',
        ':quan' => $deduct,
        ':unit' => 'Sacks'
    ]);

    header("Location: ../inventory.php?success=1");
    exit;
}

header("Location: ../inventory.php?error=1");
exit;
?>
