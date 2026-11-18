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

## Panel

WooCommerce → Loyverse Sync: estado de la sincronización, productos vinculados, imágenes pendientes, órdenes pendientes de enviar, y botones para sincronizar ahora, reintentar imágenes o hacer una resincronización completa.

## Changelog

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
