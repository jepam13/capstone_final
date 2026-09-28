<?php 
require_once "session.php";
requireRole(['admin', 'manager']);

// Remove Item: deduct the entered amount (Sacks) from each selected row.
// Deletes the row when emptied; subtracts what was actually removed from
// total_stock (floored at 0). Completed history rows are never touched.
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['quantity']) && !isset($_POST['product_name'])) {
    $entered = (int)($_POST['quantity'] ?? 0);
    $ids = [];
    if (!empty($_POST['checklist_product_ids'])) {
        $ids = array_filter(array_map('trim', explode(',', $_POST['checklist_product_ids'])));
    } elseif (!empty($_POST['product_id'])) {
        $ids = [trim($_POST['product_id'])];
    } elseif (!empty($_POST['checklist_items']) && is_array($_POST['checklist_items'])) {
        $ids = array_map('trim', $_POST['checklist_items']);
    }
    $ids = array_unique($ids);
    if ($entered <= 0 || empty($ids)) {
        header("Location: ../inventory.php?error=1");
        exit;
    }

    $removedTotal = 0;
    foreach ($ids as $pid) {
        $stmt = $pdo->prepare("SELECT quantity, status FROM inventory WHERE prod_id = :id");
        $stmt->execute([':id' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] === 'Completed') {
            continue;
        }
        $removed = min($entered, (int)$row['quantity']);
        if ($removed <= 0) {
            continue;
        }
        $left = (int)$row['quantity'] - $removed;
        if ($left <= 0) {
            $del = $pdo->prepare("DELETE FROM inventory WHERE prod_id = :id");
            $del->execute([':id' => $pid]);
        } else {
            $upd = $pdo->prepare("UPDATE inventory SET quantity = :q, updated_at = NOW() WHERE prod_id = :id");
            $upd->execute([':q' => $left, ':id' => $pid]);
        }
        $removedTotal += $removed;
    }

    if ($removedTotal > 0) {
        // Optional ledger table: skip silently when it does not exist.
        // Live stock is always SUM(quantity) from inventory.
        try {
            $stmtTotal = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
            $stmtTotal->execute();
            $trow = $stmtTotal->fetch(PDO::FETCH_ASSOC);
            if ($trow) {
                $newTotal = (int)$trow['total_stock'] - $removedTotal;
                if ($newTotal < 0) {
                    $newTotal = 0;
                }
                $updT = $pdo->prepare("UPDATE total SET total_stock = :total");
                $updT->execute([':total' => $newTotal]);
            }
        } catch (Exception $e) {
            // no total table — nothing to update
        }
    }

    header("Location: ../inventory.php?success=1");
    exit;
}

if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['product_name']) && isset($_POST['quantity'])) {
    $product = trim($_POST['product_name']);
    $quantity = (int)$_POST['quantity'];
    $metric = trim($_POST['metrics']);
    $description = trim($_POST['description'] ?? '');
    $stock_in = $quantity;

    $date = date('Y-m-d');
    
    # Generate random ID
    $id_num = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 7);

    # Check status
    $status = "Recent";
    $statusSelection = $_POST['status'] ?? 'Recent';
    //if ($statusSelection == 'custom' && isset($_POST['status-custom'])) {
       // $status = trim($_POST['status-custom']);
    //} else
    if($statusSelection == 'Processing') {
        $status = 'Processing';
    } elseif ($statusSelection == 'Sorted') {
        $status = 'Sorted';
    } elseif ($statusSelection == 'Completed') {
        $status = 'Completed';
    }

    # Check for duplicate ID
    $stmt = $pdo->prepare("SELECT prod_id FROM inventory WHERE prod_id = :id");
    $stmt->bindValue(':id', $id_num);
    $stmt->execute();
    if($stmt->fetchColumn()) {
        // Retry with new ID
        $id_num = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 7);
    }

    $stmt = $pdo->prepare("INSERT INTO inventory (prod_id, product, quantity, unit, status, description, stock_in) VALUES (:id, :prod, :quan, :unit, :status, :description, :stock_in)");
    $stmt->bindValue(':id', $id_num);
    $stmt->bindValue(':prod', $product);
    $stmt->bindValue(':quan', $quantity);
    $stmt->bindValue(':unit', $metric);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':description', $description);
    $stmt->bindValue(':stock_in', $quantity);

    if($stmt->execute()) {
        if ($status == 'Completed') {
            $hist = $pdo->prepare("INSERT INTO history (user, action, ref_id, product, quantity, unit) VALUES (:user, 'Stock-Out Recorded', :ref, :prod, :quan, :unit)");
            $hist->execute([':user' => $_SESSION['user_name'] ?? '', ':ref' => $id_num, ':prod' => $product, ':quan' => $quantity, ':unit' => $metric]);
        }
        header("Location: ../inventory.php?success=1");
        exit;
    } else {
        header("Location: ../inventory.php?error=1");
        exit;
    }
}
?>