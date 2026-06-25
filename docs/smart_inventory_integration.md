# Smart Inventory Integration Notes

Core files added:

- `includes/smart_inventory.php`
- `database/smart_inventory_migration.sql`
- `admin/smart_inventory.php`

Run `database/smart_inventory_migration.sql` once before enabling the page hooks.

## `admin/inventory.php`

Add near the existing database/auth includes:

```php
require_once __DIR__ . '/../includes/smart_inventory.php';
```

When stock is increased, keep the old and new quantity and call:

```php
$broadcastCount = smartInventoryMaybeBroadcastRestock(
    $pdo,
    (int) $medicineId,
    (int) $stationId,
    (int) $oldQuantity,
    (int) $newQuantity
);

smartInventoryNotifyLowForecast($pdo, (int) $medicineId, (int) $stationId, 7);
```

If your inventory form now accepts batch data, insert one row per received batch:

```php
$pdo->prepare("
    INSERT INTO inventory_batches
        (inventory_id, medicine_id, station_id, batch_no, quantity, expiration_date, received_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
")->execute([
    $inventoryId,
    $medicineId,
    $stationId,
    $batchNo,
    $quantityAdded,
    $expirationDate ?: null
]);
```

To show the 60-day expiration alert:

```php
$expiringItems = smartInventoryGetExpiring($pdo, $stationId ?? null, 60);
```

Render `$expiringItems` near the top of the inventory page. Items are already sorted earliest-expiring first.

## `admin/dispense.php`

Add near the existing includes:

```php
require_once __DIR__ . '/../includes/smart_inventory.php';
```

Replace direct stock deduction during dispense/allocation with:

```php
$pdo->beginTransaction();
try {
    $fifoRoutes = smartInventoryApplyFifoDeduction(
        $pdo,
        (int) $medicineId,
        (int) $stationId,
        (int) $quantity
    );

    // Continue inserting your dispense/distribution record here.
    // Optional: store json_encode($fifoRoutes) in a batch_route or notes column.

    smartInventoryNotifyLowForecast($pdo, (int) $medicineId, (int) $stationId, 7);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

`$fifoRoutes` contains the exact batch IDs, batch numbers, expiration dates, and quantities selected.

## `admin/analytics.php`

Add near the existing includes:

```php
require_once __DIR__ . '/../includes/smart_inventory.php';
```

For each medicine shown in analytics:

```php
$estimate = smartInventoryEstimateDaysRemaining($pdo, (int) $medicineId, $stationId ?? null, 90);
```

Use:

- `$estimate['current_stock']`
- `$estimate['daily_usage']`
- `$estimate['days_remaining']`
- `$estimate['severity']`

When `days_remaining` is `null`, there is not enough distribution history yet. When severity is `warning` or `critical`, show the DoSR banner and allow admins to draft city health/superadmin dashboard notifications through `smartInventoryNotifyLowForecast()`.

## SMS Commands Added

The SMS parser now supports:

```text
STATUS MEDICINE losartan hilamonan
HELP FLOOD ZONE 4
SUBSCRIBE MAINTENANCE hilamonan Juan dela Cruz
UNSUBSCRIBE MAINTENANCE hilamonan
```

Subscription categories:

- `MATERNAL`
- `MAINTENANCE`
- `CALAMITY`
- `ALL`
