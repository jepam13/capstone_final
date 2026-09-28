<?php
require_once "php_backend/session.php";

requireRole(['admin']);

// Moving Average forecaster lives in php_backend/forecast_lib.php (shared with reports.php).
require_once "php_backend/forecast_lib.php";

// Shared seasonal lib (90 days in, 7 days out).

// Fertilizer types available for forecasting.
$prodOpts = $pdo->query("SELECT DISTINCT product FROM inventory ORDER BY product")->fetchAll(PDO::FETCH_COLUMN);

$selProduct = $_POST['product'] ?? ($prodOpts[0] ?? 'Vermicast');
if (!in_array($selProduct, $prodOpts, true)) {
    $selProduct = $prodOpts[0] ?? 'Vermicast';
}
// Fixed setup: last 90 days in, next 7 days out (seasonal weekday average).
$rangeDays = 90;
$steps = 7;

// Available history range label for the selected product.
$rangeStmt = $pdo->prepare("SELECT MIN(updated_at) AS mn, MAX(updated_at) AS mx FROM inventory WHERE status = 'Completed' AND product = :prod");
$rangeStmt->execute([':prod' => $selProduct]);
$rangeRow = $rangeStmt->fetch(PDO::FETCH_ASSOC);
$rangeLabel = ($rangeRow && $rangeRow['mn']) ? date('F Y', strtotime($rangeRow['mn'])) . ' - ' . date('F Y', strtotime($rangeRow['mx'])) : 'No completed data';

// Current inventory of the selected product (single-row total ledger).
$curStmt = $pdo->prepare("SELECT total_stock FROM total LIMIT 1");
$curStmt->execute();
$currentStock = round((float)($curStmt->fetchColumn() ?? 0), 2);

$forecast = null;
$method = '';
$histWarn = false;
$thinNotice = false;
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['generate'])) {
    $today = date('Y-m-d');

    $res = runForecast($pdo, $selProduct);
    $forecast = $res['forecast'];
    $method = $res['method'];
    $thinNotice = $res['thin'];
    $total = array_sum($forecast);

    // Record the run. The table may not exist on old DBs yet - never fatal a forecast.
    // Older tables lack method/range_days - retry without them so old DBs still save.
    // Thin-history refusals save nothing - there is no forecast to record.
    if (!$thinNotice) {
    try {
        $hist = $pdo->prepare("INSERT INTO forecasting_history (product, period_days, alpha, total_demand, daily_json, method, range_days) VALUES (:prod, :days, :alpha, :total, :daily, :method, :range)");
        $hist->execute([':prod' => $selProduct, ':days' => $steps, ':alpha' => 0, ':total' => $total, ':daily' => json_encode($forecast), ':method' => $method, ':range' => $rangeDays]);
    } catch (Exception $e) {
        try {
            $hist = $pdo->prepare("INSERT INTO forecasting_history (product, period_days, alpha, total_demand, daily_json) VALUES (:prod, :days, :alpha, :total, :daily)");
            $hist->execute([':prod' => $selProduct, ':days' => $steps, ':alpha' => 0, ':total' => $total, ':daily' => json_encode($forecast)]);
        } catch (Exception $e2) {
            $histWarn = true;
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forecasting</title>
    <link rel="stylesheet" href="style.css">
    <?php $NEED_CHART = true; require_once "php_backend/head_assets.php"; ?>
</head>
<body>
    <?php require_once "main-sidebar.php"; ?>
    <div class="forecastingpage">
        <div class="page-header">
            <h1>Demand Forecasting</h1>
        </div>
        <p class="section-desc">Generate a forecast based on historical data.</p>

        <!-- Forecast Settings -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-sliders"></i> Forecast Settings</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="forecasting.php">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="product">Fertilizer Type</label>
                        <select id="product" name="product" required>
                            <?php foreach ($prodOpts as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $selProduct === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>Historical Data</label>
                        <div><strong><?= htmlspecialchars($rangeLabel) ?></strong> (last 90 days evaluated)</div>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>Method</label>
                        <div>Seasonal Moving Average (weekday, 90-day): each of the next 7 days predicts its weekday's average (e.g. Monday = average of the last ~13 Mondays), so the weekly rise/reduce rhythm shows. Needs 90+ days of history.</div>
                    </div>
                    <input type="hidden" name="generate" value="1">

                    <button type="submit" class="btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Forecast</button>
                </form>
            </div>
        </div>

        <?php if ($thinNotice): ?>
        <div class="content-card">
            <div class="card-body">
                <p class="section-desc">Not enough history yet - forecasts need 90+ days of sales for this product.</p>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($forecast !== null && !$thinNotice): ?>
        <?php
        $total = array_sum($forecast);
        $avg = $forecast ? round($total / count($forecast), 1) : 0;
        $diff = $currentStock - $total;
        // Chart: last 30 history days actuals + 7-day forecast.
        $tailStmt = $pdo->prepare("SELECT DATE(updated_at) AS day, SUM(quantity) AS total_qty FROM inventory WHERE status = 'Completed' AND product = :prod AND updated_at >= :start GROUP BY DATE(updated_at)");
        $tailStmt->execute([':prod' => $selProduct, ':start' => date('Y-m-d', strtotime($today . ' -29 days')) . ' 00:00:00']);
        $tailMap = [];
        while ($tailRow = $tailStmt->fetch(PDO::FETCH_ASSOC)) {
            $tailMap[$tailRow['day']] = (int)$tailRow['total_qty'];
        }
        $histLabels = [];
        $histVals = [];
        $td = date('Y-m-d', strtotime($today . ' -29 days'));
        while ($td <= $today) {
            $histLabels[] = date('M d', strtotime($td));
            $histVals[] = $tailMap[$td] ?? 0;
            $td = date('Y-m-d', strtotime($td . ' +1 day'));
        }
        $fcLabels = [];
        $fd = $today;
        for ($i = 1; $i <= $steps; $i++) {
            $fd = date('Y-m-d', strtotime($fd . ' +1 day'));
            $fcLabels[] = date('M d', strtotime($fd));
        }
        ?>
        <!-- Forecast Result -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-square-poll-vertical"></i> Forecast Result</h2>
            </div>
            <div class="card-body">
                <?php if ($histWarn): ?>
                <div class="feedback-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Run computed but not saved (forecasting_history table missing - run its CREATE from database_query).</span>
                </div>
                <?php endif; ?>
                <h3 style="margin: 0 0 14px; font-size: 1.05rem;">Demand Forecast Overview</h3>
                <div class="info-cards">
                    <div class="stat-card stat-card-green">
                        <h2>Predicted Demand</h2>
                        <h3><?= rtrim(rtrim(number_format((float) $total, 2, '.', ''), '0'), '.') ?> Sacks</h3>
                        <div class="stat-sub">Next 7 days</div>
                    </div>
                    <div class="stat-card stat-card-blue">
                        <h2>Available Stock</h2>
                        <h3><?= rtrim(rtrim(number_format((float) $currentStock, 2, '.', ''), '0'), '.') ?> Sacks</h3>
                        <div class="stat-sub">Current inventory</div>
                    </div>
                    <div class="stat-card stat-card-amber">
                        <h2>Estimated Shortfall</h2>
                        <h3><?= rtrim(rtrim(number_format((float) max(0, $total - $currentStock), 2, '.', ''), '0'), '.') ?> Sacks</h3>
                        <div class="stat-sub">Illustrative planning estimate</div>
                    </div>
                    <div class="stat-card stat-card-purple">
                        <h2>Forecast Period</h2>
                        <h3>7 Days</h3>
                        <div class="stat-sub"><?= htmlspecialchars(date('M d', strtotime($today . ' +1 day')) . ' - ' . date('M d', strtotime($today . ' +7 days'))) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Historical vs Forecast -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-chart-line"></i> Historical vs Forecast</h2>
            </div>
            <div class="card-body">
                <div style="height: 320px;"><canvas id="forecastChart"></canvas></div>
            </div>
        </div>
        <script>
        const fcHistLabels = <?= json_encode($histLabels) ?>;
        const fcHistVals = <?= json_encode($histVals) ?>;
        const fcLabels = <?= json_encode($fcLabels) ?>;
        const fcVals = <?= json_encode($forecast) ?>;
        const fcNulls = new Array(fcHistVals.length).fill(null);
        new Chart(document.getElementById('forecastChart'), {
            data: {
                labels: fcHistLabels.concat(fcLabels),
                datasets: [
                    { type: 'line', label: 'Actual', data: fcHistVals.concat(new Array(fcVals.length).fill(null)), borderColor: '#22c55e', tension: 0.3, pointRadius: 2, spanGaps: false },
                    { type: 'line', label: 'Forecast', data: fcNulls.concat(fcVals), borderColor: '#3b82f6', borderDash: [6, 4], tension: 0.3, pointRadius: 2, spanGaps: false }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true }},
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Sacks' }}}
            }
        });
        </script>

        <!-- Forecasted Demand -->
        <div class="content-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-calendar-days"></i> Forecasted Demand (<?= htmlspecialchars($method) ?>, daily avg <?= htmlspecialchars($avg) ?> Sacks)</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Forecast</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $fd = $today; ?>
                            <?php foreach ($forecast as $qty): ?>
                            <?php $fd = date('Y-m-d', strtotime($fd . ' +1 day')); ?>
                            <tr>
                                <td><?= htmlspecialchars(date('M d', strtotime($fd))) ?></td>
                                <td><strong><?= htmlspecialchars($qty) ?> Sacks</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div> <!--Forecastingpage END-->
</body>
</html>
