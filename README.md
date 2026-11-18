# Loyverse Sync

Plugin de WordPress que sincroniza Loyverse con WooCommerce para pequenaspequenitas.com.

Sincroniza productos, variantes, imágenes e inventario de Loyverse a WooCommerce, y envía las órdenes de WooCommerce como recibos a Loyverse.

## Instalación

1. Sube `loyverse-sync.php` a `wp-content/plugins/` o instálalo desde el panel de WordPress.
2. Activa el plugin. Requiere WooCommerce.
3. Configura el token de Loyverse en WooCommerce → Loyverse Sync, o en `wp-config.php`:

```php
define( 'LVS_TOKEN_SECRET', 'tu_token' );
// Opcionales:
define( 'LVS_STORE_ID', 'id-de-tienda-loyverse' );
define( 'LVS_PAYMENT_TYPE_ID', 'id-tipo-de-pago-loyverse' );
```

## Sincronización

- Cron incremental cada 10 minutos y reconciliación completa semanal.
- Productos y variantes que ya no existen en Loyverse se eliminan en WooCommerce: los productos van a la papelera y las variaciones se borran de forma permanente.
- La reconciliación global de variaciones usa el endpoint `/variants` de Loyverse, así no depende de que el item haya cambiado su `updated_at`.
- Órdenes de WooCommerce en estado `processing` o `completed` se envían como recibos a Loyverse.
- **No sobrescribir ediciones (opcional, 1.1.3):** con la opción activada en el panel, un producto que ya existe conserva el nombre y la descripción editados en WooCommerce; solo se actualizan precio, stock, imagen, categoría y variaciones. Los productos nuevos sí toman los datos de Loyverse.
- **Modo simulación (opcional, 1.1.3):** con la opción activada, la reconciliación de productos y de variaciones no borra ni manda nada a la papelera; solo registra en el log (`[Simulación] …`) lo que se borraría. Útil para revisar una limpieza antes de aplicarla.
- **Avisos de error (1.1.3):** el último error relevante (API/token, errores de sync, reconciliación abortada) se muestra en el panel y como aviso en wp-admin si es de las últimas 24h. Se limpia solo cuando una sincronización completa termina sin errores. No se envían correos.
- **Sincronizar un solo producto (1.1.3):** en la edición de cada producto hay un meta box "Loyverse Sync" con el ID de Loyverse vinculado y un botón para traer ese producto fresco de Loyverse y sincronizar solo ese producto.

## Panel

WooCommerce → Loyverse Sync: estado de la sincronización, productos vinculados, imágenes pendientes, órdenes pendientes de enviar, y botones para sincronizar ahora, reintentar imágenes o hacer una resincronización completa.

## Changelog

### 1.1.3
- No pisar ediciones en Woo: opción "No sobrescribir nombre y descripción editados en WooCommerce" (apagada por defecto, `lvs_preserve_edits`); con ella activa, un producto existente conserva nombre y descripción, y solo se actualizan precio, stock, imagen, categoría y variaciones. Los productos nuevos sí toman los datos de Loyverse.
- Modo simulación: opción "Modo simulación (no borrar nada)" (apagada por defecto, `lvs_dry_run`); la reconciliación de productos y de variaciones, `lvs_delete_item()`, `lvs_sync_variations()` y `lvs_delete_all_variations()` no borran ni mandan nada a la papelera, solo registran `[Simulación] …` en el log, y la reconciliación se da por completada sin quedar pendiente. El panel muestra un aviso cuando está activo. La protección anti-borrado masivo no cambia.
- Avisos cuando algo falla: el último error relevante queda en `lvs_last_error` (mensaje + fecha), se muestra en una tarjeta roja en el panel y como `admin_notice` en wp-admin si es de las últimas 24h (solo para quien puede gestionar WooCommerce, con enlace al panel). Se limpia cuando una sincronización completa termina sin errores. Sin correos.
- Sincronizar un solo producto: meta box "Loyverse Sync" en la edición del producto, con el ID de Loyverse vinculado y un botón "Sincronizar este producto ahora" (nonce + capability) que trae `GET /items/{id}` fresco y ejecuta `lvs_upsert_item()` solo para ese producto, con aviso de éxito/error al volver. Sin ID vinculado no muestra el botón.

### 1.1.2
- Las variantes borradas en Loyverse (con `deleted_at`) ya no resucitan en WooCommerce: se ignoran en `/items`, en el barrido global de `/variants` y al buscar el producto por `variant_id`/SKU, así que la reconciliación global las elimina de Woo. Corrige el caso en que un producto con 2 variantes vivas mostraba tallas viejas borradas tras forzar un sync.

### 1.1.1
- Solo rediseño estético del panel admin: cabecera moderna, tarjetas de estado, botones y barra de progreso renovados. Sin cambios en sincronización, AJAX, opciones ni nonces.

### 1.1.0
- Reconciliación global de variaciones vía `GET /variants` en cada corrida.
- Conversión variable → simple robusta: busca el producto también por el `_loyverse_variant_id` de sus variaciones, elimina todos los posts de variación hijos, limpia atributos y aplica SKU/precio de la única variación restante.
- La reconciliación de productos ya no se pierde si la pasada se interrumpe: queda pendiente y se reintenta en la siguiente corrida.

### 1.0.0
- Versión inicial: productos, variantes, imágenes, inventario, eliminaciones y órdenes → recibos.
