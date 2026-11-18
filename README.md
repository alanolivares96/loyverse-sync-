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
- **Mantener variable con 1 variación (1.3.0, activado por defecto, `lvs_keep_variable`):** un producto cuyas variaciones en Loyverse tengan opciones (por ejemplo, TALLA) se queda como producto variable aunque solo le quede una variación viva, para que los clientes vean qué talla hay disponible. Sin opciones en Loyverse, el producto sigue siendo simple; con la opción apagada, un producto con 1 variación vuelve a ser simple como antes. También aplica en la reconciliación global: un variable al que solo le queda 1 variación ya no se convierte a simple si tiene opciones.
- **Recuperación de cursor (1.2.2):** los cursores de paginación de Loyverse caducan; si una enumeración falla con un cursor guardado (`INVALID_CURSOR`), el plugin borra el cursor muerto y reintenta una sola vez desde el inicio, en lugar de quedarse atascado reintentando el mismo cursor en cada corrida.
- **Webhooks (1.2.0):** endpoint REST propio `POST /wp-json/loyverse-sync/v1/webhook`. Loyverse sí ofrece webhooks (se crean en el Back Office → Webhooks o con `POST /webhooks`; eventos: receipts, items, inventory levels y customers actualizados, y shifts creados), pero solo los creados por OAuth llevan la cabecera `X-Loyverse-Signature` (HMAC-SHA1); los creados con un token personal no llevan firma. Por eso el endpoint verifica un secreto compartido propio (`lvs_webhook_secret`, generado solo, visible y regenerable en el panel) como `?secret=...` o cabecera `X-Loyverse-Secret` (también se acepta `X-Loyverse-Signature` si coincide con HMAC-SHA1 del cuerpo con ese secreto). El webhook solo avisa de que algo cambió: el plugin relee el item con `GET /items/{id}` y nunca confía en los datos del payload. El cron de 10 minutos sigue como respaldo por si un webhook se pierde. El registro en Loyverse es manual (Back Office → Webhooks, eventos de items y de niveles de inventario).
- **Atributos globales (1.2.0, activado por defecto, `lvs_global_attrs`):** las opciones de Loyverse se crean como atributos globales `pa_*` con sus términos, así sirven para filtros de tienda. Los productos existentes migran solos en la siguiente sincronización. Apagado, se usan atributos personalizados como antes.
- **Sincronización por categorías (1.2.0, `lvs_sync_categories`, vacía = todas):** multi-select en el panel con las categorías de Loyverse. Los items fuera de la selección no se crean ni se actualizan, y los productos que ya existen en Woo y quedan fuera NO se borran: solo dejan de sincronizarse.
- **Reembolsos y cancelaciones (1.2.0):** al reembolsar una orden en Woo se crea un recibo REFUND en Loyverse con `POST /receipts/{receipt_number}/refund` (líneas por id de línea del recibo original, cantidades limitadas a lo vendido). El reembolso repone el stock en Loyverse y no se duplica (una orden ya reembolsada no se vuelve a reembolsar; si falla, queda pendiente y se reintenta). Límites de la API de Loyverse: solo recibos pagados con un único pago, y no para recibos pagados con pagos integrados (iZettle, SumUp, etc.); en esos casos hay que reembolsar desde la app de Loyverse. La API NO tiene endpoint para anular un recibo ya creado: una orden cancelada en Woo no anula su recibo en Loyverse y debe cancelarse a mano en el Back Office (Recibos → recibo → Cancelar). Si la orden se cancela antes de enviarse, no se crea recibo.
- **Precios por tienda y ofertas (1.2.0):** si hay una tienda configurada (`LVS_STORE_ID` en `wp-config.php` o el campo "Tienda para precios por tienda" del panel), el precio de cada variante se toma del `stores[]` de esa tienda; si no, del `default_price`. Loyverse NO expone precio de oferta por variante ni por item (los descuentos son un recurso aparte y se aplican en el recibo), así que no se inventa un `sale_price`: el precio de oferta puesto a mano en Woo no se sobrescribe.
- **No sobrescribir ediciones (opcional, 1.1.3):** con la opción activada en el panel, un producto que ya existe conserva el nombre y la descripción editados en WooCommerce; solo se actualizan precio, stock, imagen, categoría y variaciones. Los productos nuevos sí toman los datos de Loyverse.
- **Modo simulación (opcional, 1.1.3):** con la opción activada, la reconciliación de productos y de variaciones no borra ni manda nada a la papelera; solo registra en el log (`[Simulación] …`) lo que se borraría. Útil para revisar una limpieza antes de aplicarla.
- **Avisos de error (1.1.3):** el último error relevante (API/token, errores de sync, reconciliación abortada) se muestra en el panel y como aviso en wp-admin si es de las últimas 24h. Se limpia solo cuando una sincronización completa termina sin errores. No se envían correos.
- **Sincronizar un solo producto (1.1.3):** en la edición de cada producto hay un meta box "Loyverse Sync" con el ID de Loyverse vinculado y un botón para traer ese producto fresco de Loyverse y sincronizar solo ese producto.

## Panel

WooCommerce → Loyverse Sync: estado de la sincronización, productos vinculados, imágenes pendientes, órdenes pendientes de enviar, y botones para sincronizar ahora, reintentar imágenes o hacer una resincronización completa.

## Changelog

### 1.3.0
- Mantener variable con 1 variación: nueva opción "Mantener productos variables aunque solo quede una variación" (`lvs_keep_variable`, activada por defecto) en Ajustes de sincronización, con su fila de estado en el panel. Con ella activa, un producto con opciones en Loyverse (por ejemplo, TALLA) se trata como variable aunque solo le quede 1 variación viva: se le ponen sus atributos y se sincroniza su única variación con `lvs_sync_variations()`, sin pasar por la conversión a simple ni borrar sus variaciones. Sin opciones, sigue siendo simple; apagada, 1 variación vuelve a ser simple como antes. La misma regla aplica en la reconciliación global (`lvs_refresh_single_variant_parent()` / `lvs_promote_single_variation()`): con la opción activa y atributos de variación, el producto se queda variable con su única variación. Caso Vestido sequin bow: con 1 variación viva (4-5 A, opción TALLA), tras sincronizar queda variable con esa única variación mostrando la talla disponible.

### 1.2.2
- Cursor de paginación caducado (`INVALID_CURSOR`): si `GET /inventory`, `/items` o `/variants` falla con un cursor guardado, el cursor muerto se borra y la enumeración se reintenta una sola vez desde el inicio; si el reintento falla, no queda cursor guardado. Antes, el cursor muerto se reintentaba en cada corrida y el inventario (o la enumeración) dejaba de avanzar para siempre. La enumeración parcial de `/variants` nunca se usa para reconciliar.
- Diagnóstico: si un item llega sin variantes vivas, queda en el log; la sincronización individual registra cuántas variantes devolvió Loyverse y cuántas quedaron vivas.

### 1.2.1
- Sincronizar un solo producto: el botón "Sincronizar este producto ahora" del meta box ya no usa un `<form>` anidado dentro de la pantalla de edición del producto (HTML inválido: el navegador ignoraba el formulario interno y el botón terminaba enviando el formulario principal, recargando al listado de entradas sin sincronizar nada). Ahora es un enlace con nonce a `admin-post.php?action=lvs_sync_one` y el handler lee `product_id` de `$_REQUEST`.

### 1.2.0
- Webhooks de Loyverse: endpoint REST `POST /wp-json/loyverse-sync/v1/webhook` con verificación por secreto compartido (`lvs_webhook_secret`, generado solo, visible/copiable y regenerable en el panel, opción `lvs_webhook_last` con el último recibido). Al recibir un evento de items o de inventario, sincroniza solo ese item (releyéndolo con `GET /items/{id}`) y actualiza su stock. El cron de 10 minutos sigue como respaldo. Registro manual en Loyverse (Back Office → Webhooks). Límite documentado: solo los webhooks creados por OAuth llevan `X-Loyverse-Signature`; los de token personal no llevan firma, por eso se verifica el secreto propio.
- Atributos globales de WooCommerce: opción `lvs_global_attrs` (activada por defecto); las opciones de Loyverse se crean como atributos globales `pa_*` (con `wc_create_attribute` si no existen) y términos, y las variaciones los usan en lugar de atributos personalizados. Los productos existentes migran solos en la siguiente sincronización.
- Sincronización por categorías: multi-select en el panel (`lvs_sync_categories`, vacía = todas); los items fuera de la selección se saltan sin crearse ni actualizarse, y los productos ya existentes fuera de la selección no se borran, solo dejan de sincronizarse.
- Reembolsos y cancelaciones: al reembolsar una orden se crea un recibo REFUND en Loyverse con `POST /receipts/{receipt_number}/refund` (sin duplicar; con reintento si falla). Límites de la API: solo recibos de un único pago y no para pagos integrados. La API no permite anular un recibo: una orden cancelada no anula su recibo en Loyverse (cancelación manual en el Back Office); si se cancela antes de enviarse, no se crea recibo.
- Precios por tienda y ofertas: con tienda configurada (`LVS_STORE_ID` o campo en el panel) el precio de cada variante se toma del `stores[]` de esa tienda; si no, del `default_price`. Loyverse no expone precio de oferta por variante/item, así que no se inventa `sale_price` y el precio de oferta manual de Woo no se sobrescribe.

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
