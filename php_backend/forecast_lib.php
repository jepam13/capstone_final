<?php
// Seasonal Moving Average forecaster (pure PHP, no extensions).
// Uses last 90 days daily Completed totals (gaps = 0), grouped by weekday:
// each future day predicts its weekday's 90-day average (e.g. each future
// Monday = average of the last ~13 Mondays). Needs 90+ days of history
// (first sale to today), forecasts the next 7 days so the weekly
// rise/reduce rhythm stays readable. Shared by forecasting.php and
// reports.php so both pages can never drift apart.

function runForecast($pdo, $product, $rangeDays = 90, $steps = 7, $selMethod = null) {
    $rangeDays = 90;
    $steps = 7;
    $today = date('Y-m-d');

    // History span = first Completed sale to today. Gates the forecast.
    $firstStmt = $pdo->prepare("SELECT MIN(updated_at) AS mn FROM inventory WHERE status = 'Completed' AND product = :prod");
    $firstStmt->execute([':prod' => $product]);
    $firstRow = $firstStmt->fetch(PDO::FETCH_ASSOC);
    if (!$firstRow || empty($firstRow['mn'])) {
        return ['forecast' => [], 'method' => '', 'note' => '', 'average' => 0, 'thin' => true];
    }
    $histDays = (int)(floor((strtotime($today) - strtotime(date('Y-m-d', strtotime($firstRow['mn'])))) / 86400) + 1);
    if ($histDays < 90) {
        return ['forecast' => [], 'method' => '', 'note' => '', 'average' => 0, 'thin' => true];
    }

    // Daily completed totals, oldest -> today, missing days = 0.
    $start_date = date('Y-m-d', strtotime($today . ' -' . ($rangeDays - 1) . ' days'));
    $stmt = $pdo->prepare("SELECT DATE(updated_at) AS day, SUM(quantity) AS total_qty FROM inventory WHERE status = 'Completed' AND product = :prod AND updated_at BETWEEN :start AND :end GROUP BY DATE(updated_at)");
    $stmt->execute([':prod' => $product, ':start' => $start_date . ' 00:00:00', ':end' => $today . ' 23:59:59']);
    $qty_map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $qty_map[$row['day']] = (float)$row['total_qty'];
    }
    $data = [];
    $day = $start_date;
    while ($day <= $today) {
        $data[] = $qty_map[$day] ?? 0;
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }

    $average = round(array_sum($data) / $rangeDays, 1);
    // Weekday averages: one bucket per weekday (1=Mon..7=Sun).
    $sums = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $day = $start_date;
    foreach ($data as $v) {
        $w = (int)date('N', strtotime($day));
        $sums[$w] += $v;
        $counts[$w]++;
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }
    $avgW = [];
    for ($w = 1; $w <= 7; $w++) {
        $avgW[$w] = $counts[$w] > 0 ? round($sums[$w] / $counts[$w], 1) : 0;
    }
    // Next 7 days follow their weekday's average, so the weekly
    // rise/reduce rhythm shows instead of a flat line.
    $forecast = [];
    $fd = $today;
    for ($h = 0; $h < $steps; $h++) {
        $fd = date('Y-m-d', strtotime($fd . ' +1 day'));
        $forecast[] = max(0, $avgW[(int)date('N', strtotime($fd))]);
    }
    return ['forecast' => $forecast, 'method' => 'Seasonal Moving Average (weekday, 90-day)', 'note' => '', 'average' => $average, 'thin' => false];
}
