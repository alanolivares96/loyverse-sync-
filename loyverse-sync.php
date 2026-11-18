<?php
/**
 * Plugin Name: Loyverse Sync
 * Description: Sincroniza Loyverse con WooCommerce: productos, variantes, imágenes, inventario, eliminaciones y órdenes → recibos.
 * Version: 1.1.2
 * Requires Plugins: woocommerce
 *
 * Novedades 1.1.2:
 *  - Variantes borradas en Loyverse ya no resucitan en Woo: se ignoran
 *    las variantes con deleted_at en /items, en /variants y al buscar
 *    el producto por variant_id/SKU. El barrido global las trata como
 *    huerfanas y las elimina de Woo. Sin cambios en el panel.
 *
 * Novedades 1.1.1:
 *  - Solo rediseño estético del panel admin (WooCommerce → Loyverse Sync):
 *    cabecera moderna, tarjetas de estado, botones y barra de progreso
 *    renovados. Sin cambios en sincronización, AJAX, opciones ni nonces.
 *
 * Novedades 1.1.0:
 *  - Reconciliación global de variaciones vía GET /variants en cada corrida:
 *    borra en Woo las variaciones que ya no existen en Loyverse aunque el
 *    item no haya cambiado su updated_at (ya no depende del sync incremental).
 *  - Conversión variable → simple robusta: busca el producto también por el
 *    _loyverse_variant_id de sus variaciones, elimina TODOS los posts de
 *    variación hijos, limpia atributos y aplica SKU/precio de la única
 *    variación restante sobre el producto simple resultante.
 *  - Conversión simple → variable: limpia SKU/precio/variant_id del padre
 *    antes de crear las variaciones (evita SKUs duplicados).
 *  - Reconciliación de productos persistente: al terminar la enumeración
 *    completa, la lista de IDs vistos se guarda en 'lvs_reconcile_pending'
 *    ANTES de reconciliar; si esa corrida se interrumpe o la protección
 *    anti-borrado aborta, la siguiente corrida la reintenta sin tener que
 *    re-enumerar todo el catálogo. No se pierde.
 *  - lvs_sync_variations() también detecta variaciones huérfanas por
 *    post_parent que get_children() no devuelve (papelera/estados raros).
 *
 * wp-config.php:
 *   define( 'LVS_TOKEN_SECRET', 'tu_token' );
 *   // Opcionales:
 *   define( 'LVS_STORE_ID', 'id-de-tienda-loyverse' );
 *   define( 'LVS_PAYMENT_TYPE_ID', 'id-tipo-de-pago-loyverse' );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LVS_API', 'https://api.loyverse.com/v1.0' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/* =========================================================
 * Utilidades
 * ======================================================= */
function lvs_token() {
	if ( defined( 'LVS_TOKEN_SECRET' ) ) return LVS_TOKEN_SECRET;
	return (string) get_option( 'lvs_token_opt', '' );
}

function lvs_log( $msg, $level = 'info' ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'loyverse-sync' ] );
	}
}

function lvs_iso( $ts = null ) {
	return gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ? $ts : time() );
}

// Variantes vivas de Loyverse: /items se pide con show_deleted=true para
// detectar productos eliminados, pero esa respuesta tambien devuelve las
// variantes borradas del item (con deleted_at). Sin este filtro, cada sync
// recreaba en Woo las tallas ya borradas en Loyverse (caso "Vestido sequin
// bow": 2 variantes vivas + tallas borradas que reaparecian). Solo se mira
// deleted_at, ningun otro campo inventado.
function lvs_live_variants( $variants ) {
	if ( ! is_array( $variants ) ) return [];
	$out = [];
	foreach ( $variants as $v ) {
		if ( ! is_array( $v ) ) continue;
		if ( ! empty( $v['deleted_at'] ) ) continue;
		$out[] = $v;
	}
	return $out;
}

function lvs_request( $method, $path, $args = [], $body = null ) {
	$url = LVS_API . $path;
	if ( $args ) $url = add_query_arg( $args, $url );
	$opts = [
		'method'  => $method,
		'timeout' => 30,
		'headers' => [
			'Authorization' => 'Bearer ' . lvs_token(),
			'Content-Type'  => 'application/json',
		],
	];
	if ( $body !== null ) $opts['body'] = wp_json_encode( $body );

	for ( $i = 0; $i < 3; $i++ ) {
		$res = wp_remote_request( $url, $opts );
		if ( is_wp_error( $res ) ) {
			lvs_log( "Error de red $method $path: " . $res->get_error_message(), 'error' );
			sleep( 1 );
			continue;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 429 ) { sleep( 2 ); continue; }
		$raw  = wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 ) return [ 'ok' => true, 'data' => $data ];
		lvs_log( "HTTP $code $method $path: $raw", 'error' );
		return [ 'ok' => false, 'code' => $code, 'data' => $data ];
	}
	return [ 'ok' => false ];
}

function lvs_get( $path, $args = [] ) {
	$r = lvs_request( 'GET', $path, $args );
	return $r['ok'] ? $r['data'] : null;
}

function lvs_store_id() {
	if ( defined( 'LVS_STORE_ID' ) ) return LVS_STORE_ID;
	$id = get_option( 'lvs_store_id' );
	if ( ! $id ) {
		$s  = lvs_get( '/stores' );
		$id = isset( $s['stores'][0]['id'] ) ? $s['stores'][0]['id'] : '';
		if ( $id ) update_option( 'lvs_store_id', $id, false );
	}
	return $id;
}

function lvs_payment_type_id() {
	if ( defined( 'LVS_PAYMENT_TYPE_ID' ) ) return LVS_PAYMENT_TYPE_ID;
	$id = get_option( 'lvs_payment_type_id' );
	if ( ! $id ) {
		$r = lvs_get( '/payment_types' );
		$list = isset( $r['payment_types'] ) ? $r['payment_types'] : [];
		foreach ( $list as $pt ) {
			if ( ( isset( $pt['type'] ) ? $pt['type'] : '' ) === 'OTHER' ) { $id = $pt['id']; break; }
		}
		if ( ! $id && $list ) $id = $list[0]['id'];
		if ( $id ) update_option( 'lvs_payment_type_id', $id, false );
	}
	return $id;
}

/* =========================================================
 * Cron
 * ======================================================= */
add_filter( 'cron_schedules', function ( $s ) {
	$s['lvs_10min']  = [ 'interval' => 600,    'display' => 'Cada 10 minutos' ];
	$s['lvs_weekly'] = [ 'interval' => 604800, 'display' => 'Semanal' ];
	return $s;
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'lvs_sync_event' ) )   wp_schedule_event( time() + 60, 'lvs_10min', 'lvs_sync_event' );
	if ( ! wp_next_scheduled( 'lvs_weekly_event' ) ) wp_schedule_event( time() + 3600, 'lvs_weekly', 'lvs_weekly_event' );
	if ( ! get_option( 'lvs_activated_at' ) ) update_option( 'lvs_activated_at', time(), false ); // solo órdenes posteriores
	lvs_reset_full();
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'lvs_sync_event' );
	wp_clear_scheduled_hook( 'lvs_weekly_event' );
} );

add_action( 'lvs_sync_event', 'lvs_run_sync' );
add_action( 'lvs_weekly_event', 'lvs_reset_full' ); // reconciliación completa semanal

function lvs_reset_full() {
	update_option( 'lvs_state', [
		'full_done'    => false,
		'cursor'       => '',
		'processed'    => 0,
		'last_sync'    => '',
		'full_started' => lvs_iso( time() - 300 ),
	], false );
	delete_option( 'lvs_seen' );
	delete_option( 'lvs_seen_variants' );
	delete_option( 'lvs_var_seen' );
	delete_option( 'lvs_var_state' );
	delete_option( 'lvs_reconcile_pending' );
}

/* =========================================================
 * Orquestador
 * ======================================================= */
function lvs_progress( $phase, $done = null, $total = null, $running = true ) {
	$labels = [ 'items' => 'Productos', 'images' => 'Imágenes', 'inventory' => 'Inventario', 'orders' => 'Órdenes', 'done' => 'Completado' ];
	update_option( 'lvs_progress', [
		'running' => $running,
		'phase'   => $phase,
		'label'   => isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase,
		'done'    => $done,
		'total'   => $total,
		'ts'      => time(),
	], false );
}

function lvs_progress_payload() {
	wp_cache_delete( 'lvs_progress', 'options' );
	$p = wp_parse_args( get_option( 'lvs_progress', [] ), [
		'running' => false, 'phase' => 'done', 'label' => '', 'done' => null, 'total' => null, 'ts' => 0,
	] );
	$p['pct'] = ( $p['total'] && $p['done'] !== null ) ? min( 99, (int) round( $p['done'] / $p['total'] * 100 ) ) : null;
	$p['now'] = time();
	return $p;
}

function lvs_run_sync( $force_full = false, $budget = 220 ) {
	global $lvs_deadline;
	if ( get_transient( 'lvs_lock' ) ) return false;
	set_transient( 'lvs_lock', 1, $budget + 120 );
	@set_time_limit( $budget + 60 );
	@ini_set( 'memory_limit', '512M' );
	$t0 = time();
	lvs_progress( 'items', 0, null, true );

	try {
		if ( $force_full ) lvs_reset_full();
		// Reconciliación de productos pendiente de una corrida interrumpida:
		// se reintenta primero, sin re-enumerar el catálogo.
		lvs_run_product_reconcile();
		$lvs_deadline = $t0 + (int) ( $budget * 0.45 );
		lvs_sync_items();
		// Barrido global de variaciones (GET /variants), independiente del
		// updated_at de los items: atrapa variaciones borradas en Loyverse
		// aunque el producto padre no haya cambiado.
		$lvs_deadline = $t0 + (int) ( $budget * 0.65 );
		lvs_sync_variants_global( $force_full );
		$lvs_deadline = $t0 + (int) ( $budget * 0.85 );
		lvs_fix_images( 60 );
		$lvs_deadline = $t0 + $budget;
		lvs_sync_inventory();
		lvs_retry_orders();
	} catch ( Throwable $e ) {
		lvs_log( 'Error en sync: ' . $e->getMessage(), 'error' );
	}
	delete_transient( 'lvs_lock' );
	lvs_progress( 'done', null, null, false );
	return true;
}

/* =========================================================
 * Productos
 * ======================================================= */
function lvs_categories() {
	$c   = lvs_get( '/categories', [ 'limit' => 250 ] );
	$out = [];
	foreach ( ( isset( $c['categories'] ) ? $c['categories'] : [] ) as $x ) $out[ $x['id'] ] = $x['name'];
	return $out;
}

function lvs_sync_items() {
	global $lvs_deadline;

	$st = wp_parse_args( get_option( 'lvs_state', [] ), [
		'full_done' => false, 'cursor' => '', 'last_sync' => '', 'full_started' => '', 'processed' => 0,
	] );
	$full    = ! $st['full_done'];
	$started = $full && $st['full_started'] ? $st['full_started'] : lvs_iso( time() - 300 );
	$cats    = lvs_categories();
	$seen    = $full ? (array) get_option( 'lvs_seen', [] ) : [];
	$seen_var = $full ? (array) get_option( 'lvs_seen_variants', [] ) : [];
	$cursor  = $full ? $st['cursor'] : '';
	$processed = $full ? (int) $st['processed'] : 0;
	$total     = $full ? (int) get_option( 'lvs_items_total', 0 ) : 0;

	do {
		$args = [ 'limit' => 25, 'show_deleted' => 'true' ];
		if ( ! $full && $st['last_sync'] ) $args['updated_at_min'] = $st['last_sync'];
		if ( $cursor ) $args['cursor'] = $cursor;

		$data = lvs_get( '/items', $args );
		if ( ! is_array( $data ) ) return; // error: conserva estado y reintenta luego

		foreach ( ( isset( $data['items'] ) ? $data['items'] : [] ) as $item ) {
			$processed++;
			if ( $processed % 10 === 0 ) lvs_progress( 'items', $processed, $total > $processed ? $total : null );
			try {
				if ( ! empty( $item['deleted_at'] ) ) {
					lvs_delete_item( $item['id'] );
					continue;
				}
				lvs_upsert_item( $item, $cats );
				if ( $full ) {
					$seen[] = $item['id'];
					foreach ( lvs_live_variants( isset( $item['variants'] ) ? $item['variants'] : [] ) as $sv ) {
						if ( ! empty( $sv['variant_id'] ) ) $seen_var[] = $sv['variant_id'];
					}
				}
			} catch ( Throwable $e ) {
				lvs_log( 'Error item ' . $item['id'] . ' (' . ( isset( $item['item_name'] ) ? $item['item_name'] : '' ) . '): ' . $e->getMessage(), 'error' );
			}
		}

		$cursor = isset( $data['cursor'] ) ? $data['cursor'] : '';
		if ( $full ) {
			$st['cursor']    = $cursor;
			$st['processed'] = $processed;
			update_option( 'lvs_state', $st, false );
			update_option( 'lvs_seen', $seen, false );
			update_option( 'lvs_seen_variants', $seen_var, false );
		}
	} while ( $cursor && time() < $lvs_deadline );

	if ( $cursor ) return; // sigue en la siguiente corrida

	if ( $full ) {
		update_option( 'lvs_items_total', count( $seen ), false );
		// Guarda la foto completa ANTES de reconciliar: si el proceso muere
		// o la protección anti-borrado aborta, lvs_run_product_reconcile()
		// la reintenta en la siguiente corrida con esta misma lista.
		update_option( 'lvs_reconcile_pending', [
			'seen'     => array_values( array_unique( $seen ) ),
			'seen_var' => array_values( array_unique( $seen_var ) ),
			'ts'       => time(),
		], false );
		delete_option( 'lvs_seen' );
		delete_option( 'lvs_seen_variants' );
		$st['full_done'] = true;
		$st['cursor']    = '';
	}
	$st['last_sync'] = $started;
	update_option( 'lvs_state', $st, false );
	if ( $full ) lvs_run_product_reconcile();
}

function lvs_find_by_meta( $key, $val ) {
	$ids = get_posts( [
		'post_type'   => 'product',
		'post_status' => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
		'meta_key'    => $key,
		'meta_value'  => $val,
		'fields'      => 'ids',
		'numberposts' => 1,
	] );
	return $ids ? (int) $ids[0] : 0;
}

function lvs_find_parent_by_variant_id( $variant_id ) {
	global $wpdb;
	$cid = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_loyverse_variant_id' AND meta_value = %s LIMIT 1",
		$variant_id
	) );
	if ( ! $cid ) return 0;
	$parent = (int) get_post_field( 'post_parent', $cid );
	return $parent ? $parent : $cid; // sin padre: el propio post es el producto simple
}

function lvs_find_product( $item ) {
	$id = lvs_find_by_meta( '_loyverse_id', $item['id'] );
	if ( $id ) return $id;
	// Por variant_id de alguna de sus variaciones: atrapa el caso en que el
	// producto Woo existe (p. ej. variable creado a mano o por otro plugin,
	// sin _loyverse_id) y Loyverse ya solo conserva UNA de sus variaciones.
	// Sin esto se creaba un producto simple nuevo y el variable viejo,
	// con todas sus variaciones, quedaba intacto.
	foreach ( lvs_live_variants( isset( $item['variants'] ) ? $item['variants'] : [] ) as $v ) {
		if ( empty( $v['variant_id'] ) ) continue;
		$pid = lvs_find_parent_by_variant_id( $v['variant_id'] );
		if ( $pid ) return $pid;
	}
	// Adopta productos existentes (ej. creados por SKUPlugs) por SKU
	foreach ( lvs_live_variants( isset( $item['variants'] ) ? $item['variants'] : [] ) as $v ) {
		if ( empty( $v['sku'] ) ) continue;
		$pid = wc_get_product_id_by_sku( $v['sku'] );
		if ( ! $pid ) continue;
		$p = wc_get_product( $pid );
		if ( $p ) return $p->is_type( 'variation' ) ? (int) $p->get_parent_id() : (int) $pid;
	}
	return 0;
}

function lvs_delete_item( $loyverse_id ) {
	$id = lvs_find_by_meta( '_loyverse_id', $loyverse_id );
	if ( $id && get_post_status( $id ) !== 'trash' ) {
		wp_trash_post( $id ); // a la papelera (recuperable)
		lvs_log( "Eliminado en Loyverse → papelera en Woo: #$id" );
	}
}

// Ejecuta la reconciliación de productos guardada en 'lvs_reconcile_pending'.
// Devuelve true si quedó resuelta (y limpia la opción); false si sigue pendiente.
function lvs_run_product_reconcile() {
	$p = get_option( 'lvs_reconcile_pending', null );
	if ( ! is_array( $p ) || ! isset( $p['seen'] ) ) return true;
	if ( lvs_reconcile( (array) $p['seen'] ) ) {
		delete_option( 'lvs_reconcile_pending' );
		update_option( 'lvs_reconcile_last', time(), false );
		return true;
	}
	lvs_log( 'Reconciliación de productos sigue pendiente: se reintentará en la siguiente corrida.', 'warning' );
	return false;
}

// Elimina del sitio lo que ya no existe en Loyverse (al terminar pasada completa)
// Devuelve false si aborta (lista vacía o protección anti-borrado masivo).
function lvs_reconcile( $seen ) {
	global $wpdb;
	if ( ! $seen ) {
		lvs_log( 'Reconciliación abortada: lista de productos vistos vacía.', 'warning' );
		return false;
	}
	$seen = array_flip( $seen );
	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_loyverse_id' AND p.post_type = 'product' AND p.post_status <> 'trash'"
	);
	$orphans = [];
	foreach ( $rows as $r ) if ( ! isset( $seen[ $r->meta_value ] ) ) $orphans[] = (int) $r->post_id;
	// Protección: no borrar en masa si algo luce raro
	if ( count( $orphans ) > 5 && count( $orphans ) > count( $rows ) * 0.5 ) {
		lvs_log( 'Reconciliación abortada: ' . count( $orphans ) . ' de ' . count( $rows ) . ' serían eliminados. Queda pendiente para revisión/reintento.', 'warning' );
		return false;
	}
	foreach ( $orphans as $pid ) {
		// Borra también sus variaciones hijas: un producto en papelera con
		// variaciones publicadas puede seguir apareciendo en búsquedas.
		foreach ( lvs_variation_post_ids( $pid ) as $cid ) wp_delete_post( $cid, true );
		wp_trash_post( $pid );
		lvs_log( "Reconciliación: #$pid ya no existe en Loyverse → papelera" );
	}
	return true;
}

// IDs de TODOS los posts product_variation hijos de un producto, en cualquier
// estado. get_children() omite algunos (papelera, estados no estándar).
function lvs_variation_post_ids( $parent_id ) {
	$ids = get_posts( [
		'post_type'   => 'product_variation',
		'post_status' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' ],
		'post_parent' => (int) $parent_id,
		'fields'      => 'ids',
		'numberposts' => -1,
	] );
	return array_map( 'intval', $ids ? $ids : [] );
}

function lvs_delete_all_variations( $parent_id ) {
	foreach ( lvs_variation_post_ids( $parent_id ) as $cid ) wp_delete_post( $cid, true );
	$p = wc_get_product( $parent_id );
	if ( $p && $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $cid ) wp_delete_post( $cid, true );
	}
}

function lvs_price( $v ) {
	if ( isset( $v['default_price'] ) ) return $v['default_price'];
	foreach ( ( isset( $v['stores'] ) ? $v['stores'] : [] ) as $s ) {
		if ( isset( $s['price'] ) ) return $s['price'];
	}
	return null;
}

function lvs_apply_variant( $p, $v, $item ) {
	$sku = trim( (string) ( isset( $v['sku'] ) ? $v['sku'] : '' ) );
	if ( $sku !== '' && $sku !== $p->get_sku() ) {
		try { $p->set_sku( $sku ); }
		catch ( Exception $e ) { lvs_log( "SKU duplicado '$sku': " . $e->getMessage(), 'warning' ); }
	}
	$price = lvs_price( $v );
	if ( $price !== null ) $p->set_regular_price( (string) $price );
	$p->update_meta_data( '_loyverse_variant_id', $v['variant_id'] );
	$track = ! empty( $item['track_stock'] );
	$p->set_manage_stock( $track );
	if ( ! $track ) $p->set_stock_status( 'instock' );
}

// Atributos (opciones) para productos variables
function lvs_attr_map( $item, $variants ) {
	$names = [];
	for ( $n = 1; $n <= 3; $n++ ) {
		if ( ! empty( $item[ "option{$n}_name" ] ) ) $names[ $n ] = $item[ "option{$n}_name" ];
	}
	if ( ! $names ) $names[1] = 'Variante';

	$opts = [];
	$map  = [];
	foreach ( $variants as $i => $v ) {
		foreach ( $names as $n => $name ) {
			$val = trim( (string) ( isset( $v[ "option{$n}_value" ] ) ? $v[ "option{$n}_value" ] : '' ) );
			if ( $val === '' ) {
				$val = ! empty( $v['sku'] ) ? (string) $v['sku'] : 'Opción ' . ( $i + 1 );
			}
			$opts[ $name ][ $val ] = true;
			$map[ $v['variant_id'] ][ sanitize_title( $name ) ] = $val;
		}
	}
	$attrs = [];
	foreach ( $opts as $name => $vals ) {
		$a = new WC_Product_Attribute();
		$a->set_id( 0 );
		$a->set_name( $name );
		$a->set_options( array_map( 'strval', array_keys( $vals ) ) );
		$a->set_position( count( $attrs ) );
		$a->set_visible( true );
		$a->set_variation( true );
		$attrs[] = $a;
	}
	return [ $attrs, $map ];
}

// Cambia el tipo de un producto (simple ⇄ variable) de forma robusta.
// Devuelve el producto recargado ya con la clase Woo correcta, o null.
function lvs_convert_product_type( $id, $type, $item, $variants ) {
	// 1) Fuera TODAS las variaciones hijas (también las que get_children omite).
	lvs_delete_all_variations( $id );
	// 2) Cambio de tipo + limpieza de cachés/transients.
	wp_set_object_terms( $id, $type, 'product_type' );
	clean_post_cache( $id );
	wc_delete_product_transients( $id );
	delete_post_meta( $id, '_default_attributes' );
	$product = wc_get_product( $id );
	if ( ! $product ) return null;
	if ( $type === 'simple' ) {
		// 3) Sin atributos de variación residuales: si quedan, Woo y los
		// plugins de caché pueden seguir tratándolo como variable.
		$product->set_attributes( [] );
		delete_post_meta( $id, '_product_attributes' );
		// 4) La única variación restante manda: SKU, precio y variant_id se
		// aplican sobre el producto simple (lvs_apply_variant, vía caller).
		if ( $variants ) lvs_apply_variant( $product, $variants[0], $item );
		lvs_log( "Conversión variable → simple: #$id (" . ( isset( $item['item_name'] ) ? $item['item_name'] : '' ) . ')' );
	} else {
		// simple → variable: el padre no debe conservar SKU/precio/variant_id
		// de su antigua única variación, o chocará con la variación nueva.
		try { $product->set_sku( '' ); } catch ( Exception $e ) {}
		$product->set_regular_price( '' );
		$product->set_sale_price( '' );
		$product->delete_meta_data( '_loyverse_variant_id' );
		lvs_log( "Conversión simple → variable: #$id (" . ( isset( $item['item_name'] ) ? $item['item_name'] : '' ) . ')' );
	}
	$product->save();
	clean_post_cache( $id );
	wc_delete_product_transients( $id );
	return wc_get_product( $id );
}

function lvs_upsert_item( $item, $cats ) {
	// Solo variantes vivas: las borradas (deleted_at) no deben contar para
	// decidir variable/simple, ni crear atributos/variaciones, ni adoptarse
	// por variant_id/SKU. Si no queda ninguna viva, no se crea nada.
	$variants = lvs_live_variants( isset( $item['variants'] ) ? $item['variants'] : [] );
	if ( ! $variants ) return;
	$item['variants'] = $variants;

	$is_var  = count( $variants ) > 1;
	$type    = $is_var ? 'variable' : 'simple';
	$id      = lvs_find_product( $item );
	$is_new  = ! $id;
	$product = $id ? wc_get_product( $id ) : null;

	// Cambio de tipo (simple ⇄ variable): ruta robusta v1.1.0
	if ( $product && ! $product->is_type( $type ) ) {
		$product = lvs_convert_product_type( $id, $type, $item, $variants );
	} elseif ( $product && ! $is_var ) {
		// Ya es simple, pero pueden quedar variaciones hijas huérfanas en BD
		// de una conversión anterior a medias: se eliminan igual.
		lvs_delete_all_variations( $id );
	}
	if ( ! $product ) $product = $is_var ? new WC_Product_Variable() : new WC_Product_Simple();

	$product->set_name( $item['item_name'] );
	$product->set_description( isset( $item['description'] ) ? (string) $item['description'] : '' );
	$product->set_status( 'publish' );

	if ( ! empty( $item['category_id'] ) && isset( $cats[ $item['category_id'] ] ) ) {
		$name = $cats[ $item['category_id'] ];
		$term = term_exists( $name, 'product_cat' );
		if ( ! $term ) $term = wp_insert_term( $name, 'product_cat' );
		if ( ! is_wp_error( $term ) ) $product->set_category_ids( [ (int) $term['term_id'] ] );
	}

	$product->update_meta_data( '_loyverse_id', $item['id'] );
	$img_url  = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';
	$img_done = (string) $product->get_meta( '_loyverse_img_done' );
	$product->update_meta_data( '_loyverse_img_src', $img_url );
	// Pendiente si: URL nueva/cambiada, o el producto no tiene imagen (no pisa imágenes existentes ajenas)
	if ( $img_url && $img_done !== $img_url && ( $img_done || ! $product->get_image_id() ) ) {
		$product->update_meta_data( '_loyverse_img_pending', '1' );
	}

	if ( $is_var ) {
		list( $attrs, ) = lvs_attr_map( $item, $variants );
		$product->set_attributes( $attrs );
		$product->set_manage_stock( false );
	} else {
		lvs_apply_variant( $product, $variants[0], $item );
	}

	$id = $product->save();

	if ( $is_var ) lvs_sync_variations( $id, $item, $variants );

	// Producto nuevo → fuerza lectura completa de inventario
	if ( $is_new ) update_option( 'lvs_inv_last', '', false );
}

function lvs_sync_variations( $parent_id, $item, $variants ) {
	$parent = wc_get_product( $parent_id );
	list( , $map ) = lvs_attr_map( $item, $variants );

	$existing = [];
	$by_sku   = [];
	$all_old  = $parent->get_children();
	foreach ( $all_old as $cid ) {
		$vid = get_post_meta( $cid, '_loyverse_variant_id', true );
		if ( $vid ) {
			$existing[ $vid ] = $cid;
		} else {
			$sku = get_post_meta( $cid, '_sku', true );
			if ( $sku ) $by_sku[ $sku ] = $cid;
		}
	}

	$keep = [];
	foreach ( $variants as $v ) {
		$vid = $v['variant_id'];
		$cid = isset( $existing[ $vid ] ) ? $existing[ $vid ] : 0;
		if ( ! $cid && ! empty( $v['sku'] ) && isset( $by_sku[ $v['sku'] ] ) ) $cid = $by_sku[ $v['sku'] ];

		$var = $cid ? new WC_Product_Variation( $cid ) : new WC_Product_Variation();
		$var->set_parent_id( $parent_id );
		$var->set_status( 'publish' );
		$var->set_attributes( $map[ $vid ] );
		lvs_apply_variant( $var, $v, $item );
		$keep[] = $var->save();
	}

	// Variantes eliminadas en Loyverse: las que get_children() devolvió…
	$keep = array_map( 'intval', $keep );
	foreach ( $all_old as $cid ) {
		if ( ! in_array( (int) $cid, $keep, true ) ) wp_delete_post( $cid, true );
	}
	// …y cualquier post de variación hijo que get_children() no devolvió
	// (papelera u otros estados): si no está en $keep, ya no existe en Loyverse.
	foreach ( lvs_variation_post_ids( $parent_id ) as $cid ) {
		if ( ! in_array( (int) $cid, $keep, true ) ) wp_delete_post( $cid, true );
	}
	WC_Product_Variable::sync( $parent_id );
}


/* =========================================================
 * Reconciliación global de variaciones (v1.1.0)
 * Enumera GET /variants completo (con cursor, reanudable entre corridas)
 * y borra de Woo toda variación cuyo variant_id ya no exista en Loyverse.
 * No depende del updated_at del item padre: es la red que atrapa el caso
 * "se borraron variaciones en Loyverse y el producto no se actualizó".
 * Nunca borra con una enumeración incompleta o fallida.
 * ======================================================= */
function lvs_sync_variants_global( $force = false ) {
	global $lvs_deadline;
	$vs   = wp_parse_args( get_option( 'lvs_var_state', [] ), [ 'cursor' => '', 'active' => false ] );
	$seen = $vs['active'] ? (array) get_option( 'lvs_var_seen', [] ) : [];
	$cursor = $vs['active'] ? (string) $vs['cursor'] : '';

	do {
		$args = [ 'limit' => 250 ];
		if ( $cursor ) $args['cursor'] = $cursor;
		$data = lvs_get( '/variants', $args );
		if ( ! is_array( $data ) || ! isset( $data['variants'] ) || ! is_array( $data['variants'] ) ) {
			// Endpoint caído o respuesta inesperada: NO se borra nada y el
			// estado queda guardado para reanudar en la siguiente corrida.
			lvs_log( 'Barrido /variants interrumpido (sin respuesta válida); no se eliminó ninguna variación. Se reanuda en la próxima corrida.', 'warning' );
			update_option( 'lvs_var_state', [ 'cursor' => $cursor, 'active' => true ], false );
			update_option( 'lvs_var_seen', $seen, false );
			return false;
		}
		foreach ( $data['variants'] as $v ) {
			if ( ! empty( $v['deleted_at'] ) ) continue; // borrada en Loyverse: no es "vista" viva
			$vid = '';
			if ( ! empty( $v['variant_id'] ) ) $vid = (string) $v['variant_id'];
			elseif ( ! empty( $v['id'] ) ) $vid = (string) $v['id'];
			if ( $vid !== '' ) $seen[] = $vid;
		}
		$cursor = isset( $data['cursor'] ) ? (string) $data['cursor'] : '';
		update_option( 'lvs_var_state', [ 'cursor' => $cursor, 'active' => true ], false );
		update_option( 'lvs_var_seen', $seen, false );
	} while ( $cursor && ( ! $lvs_deadline || time() < $lvs_deadline ) );

	if ( $cursor ) return false; // enumeración a medias: prohibido reconciliar

	delete_option( 'lvs_var_state' );
	delete_option( 'lvs_var_seen' );
	$ok = lvs_reconcile_variations_global( array_values( array_unique( $seen ) ) );
	if ( $ok ) update_option( 'lvs_var_last', time(), false );
	return $ok;
}

function lvs_reconcile_variations_global( $seen_variant_ids ) {
	global $wpdb;
	if ( ! $seen_variant_ids ) {
		lvs_log( 'Reconciliación de variaciones abortada: lista de Loyverse vacía.', 'warning' );
		return false;
	}
	$seen = array_flip( $seen_variant_ids );
	$rows = $wpdb->get_results(
		"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_loyverse_variant_id' AND p.post_type = 'product_variation'"
	);
	// Los productos SIMPLES también guardan _loyverse_variant_id (es su única
	// variación): no son variaciones y no se tocan aquí.
	$orphans = [];
	$parents = [];
	foreach ( $rows as $r ) {
		if ( isset( $seen[ $r->meta_value ] ) ) continue;
		$pid = (int) $r->post_id;
		if ( ! (int) get_post_field( 'post_parent', $pid ) ) continue;
		$orphans[] = $pid;
		$parents[ (int) get_post_field( 'post_parent', $pid ) ] = true;
	}
	if ( count( $orphans ) > 5 && count( $orphans ) > count( $rows ) * 0.5 ) {
		lvs_log( 'Reconciliación de variaciones abortada: ' . count( $orphans ) . ' de ' . count( $rows ) . ' serían eliminadas.', 'warning' );
		return false;
	}
	foreach ( $orphans as $cid ) {
		lvs_log( "Reconciliación de variaciones: variación #$cid ya no existe en Loyverse → eliminada" );
		wp_delete_post( $cid, true );
	}
	// Padres afectados: si les queda 1 sola variación, hay que convertirlos
	// a simple con los datos correctos; si les quedan 2+, solo resincronizar.
	foreach ( array_keys( $parents ) as $parent_id ) {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent ) continue;
		if ( $parent->is_type( 'variable' ) ) {
			$remaining = lvs_variation_post_ids( $parent_id );
			if ( count( $remaining ) === 1 ) {
				lvs_refresh_single_variant_parent( $parent_id );
			} else {
				WC_Product_Variable::sync( $parent_id );
			}
		}
	}
	if ( $orphans ) lvs_log( 'Reconciliación de variaciones: ' . count( $orphans ) . ' eliminadas, ' . count( $parents ) . ' productos padre revisados.' );
	return true;
}

// Un variable al que solo le queda 1 variación debe pasar a simple.
// Primero intenta traer el item fresco de Loyverse (datos autoritativos);
// si eso falla, promueve la variación restante con los datos ya en Woo.
function lvs_refresh_single_variant_parent( $parent_id ) {
	$loy_id = get_post_meta( $parent_id, '_loyverse_id', true );
	if ( $loy_id ) {
		$data = lvs_get( '/items/' . $loy_id );
		$item = null;
		if ( is_array( $data ) ) {
			if ( isset( $data['variants'] ) ) $item = $data;
			elseif ( isset( $data['item']['variants'] ) ) $item = $data['item'];
		}
		$live = $item && isset( $item['variants'] ) ? lvs_live_variants( $item['variants'] ) : [];
		if ( count( $live ) === 1 ) {
			$item['variants'] = $live;
			lvs_upsert_item( $item, lvs_categories() );
			return;
		}
		if ( count( $live ) > 1 ) {
			$item['variants'] = $live;
			lvs_upsert_item( $item, lvs_categories() ); // volvió a ser variable
			return;
		}
	}
	lvs_promote_single_variation( $parent_id );
}

// Promoción local: copia SKU/precio/stock/variant_id de la única variación
// restante al padre, lo convierte a simple y limpia sus atributos.
function lvs_promote_single_variation( $parent_id ) {
	$children = lvs_variation_post_ids( $parent_id );
	if ( count( $children ) !== 1 ) return;
	$child = wc_get_product( $children[0] );
	$parent = wc_get_product( $parent_id );
	if ( ! $child || ! $parent ) return;
	$saved = [
		'sku'     => $child->get_sku(),
		'regular' => $child->get_regular_price(),
		'sale'    => $child->get_sale_price(),
		'manage'  => $child->get_manage_stock(),
		'qty'     => $child->get_stock_quantity(),
		'status'  => $child->get_stock_status(),
		'vid'     => $child->get_meta( '_loyverse_variant_id' ),
	];
	lvs_delete_all_variations( $parent_id );
	wp_set_object_terms( $parent_id, 'simple', 'product_type' );
	clean_post_cache( $parent_id );
	wc_delete_product_transients( $parent_id );
	$p = wc_get_product( $parent_id );
	if ( ! $p ) return;
	$p->set_attributes( [] );
	delete_post_meta( $parent_id, '_product_attributes' );
	delete_post_meta( $parent_id, '_default_attributes' );
	if ( $saved['sku'] !== '' ) { try { $p->set_sku( $saved['sku'] ); } catch ( Exception $e ) {} }
	if ( $saved['regular'] !== '' && $saved['regular'] !== null ) $p->set_regular_price( (string) $saved['regular'] );
	if ( $saved['sale'] !== '' && $saved['sale'] !== null ) $p->set_sale_price( (string) $saved['sale'] );
	$p->set_manage_stock( (bool) $saved['manage'] );
	if ( $saved['manage'] && $saved['qty'] !== null ) $p->set_stock_quantity( $saved['qty'] );
	$p->set_stock_status( $saved['status'] ? $saved['status'] : 'instock' );
	if ( $saved['vid'] ) $p->update_meta_data( '_loyverse_variant_id', $saved['vid'] );
	$p->save();
	lvs_log( "Conversión variable → simple (promoción local): #$parent_id, variación restante promovida." );
}

/* =========================================================
 * Imágenes
 * ======================================================= */
function lvs_attach_image( $post_id, $url, $title ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = download_url( $url, 20 );
	if ( is_wp_error( $tmp ) ) return $tmp;

	// Detecta formato real (las URLs de Loyverse pueden no traer extensión)
	$info = @getimagesize( $tmp );
	$mime = $info && ! empty( $info['mime'] ) ? $info['mime'] : '';
	$exts = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
	if ( ! isset( $exts[ $mime ] ) ) {
		@unlink( $tmp );
		return new WP_Error( 'lvs_img', 'Formato de imagen no reconocido (' . ( $mime ? $mime : 'desconocido' ) . ')' );
	}

	$file = [
		'name'     => sanitize_title( $title ? $title : 'producto' ) . '-' . substr( md5( $url ), 0, 6 ) . '.' . $exts[ $mime ],
		'tmp_name' => $tmp,
	];
	$att = media_handle_sideload( $file, $post_id, $title );
	if ( is_wp_error( $att ) ) {
		@unlink( $tmp );
		return $att;
	}
	return $att;
}

function lvs_maybe_image( $id, $url, $title ) {
	if ( ! $url ) return;
	if ( get_post_meta( $id, '_loyverse_img_done', true ) === $url && has_post_thumbnail( $id ) && ! get_post_meta( $id, '_loyverse_img_pending', true ) ) return;

	$att = lvs_attach_image( $id, $url, $title );
	if ( is_wp_error( $att ) ) {
		update_post_meta( $id, '_loyverse_img_err', $att->get_error_message() );
		lvs_log( "Imagen falló #$id ($title): " . $att->get_error_message(), 'warning' );
		return;
	}
	$old = (int) get_post_meta( $id, '_loyverse_img_att', true );
	set_post_thumbnail( $id, $att );
	update_post_meta( $id, '_loyverse_img_done', $url );
	update_post_meta( $id, '_loyverse_img_att', $att );
	delete_post_meta( $id, '_loyverse_img_err' );
	delete_post_meta( $id, '_loyverse_img_pending' );
	if ( $old && $old !== (int) $att ) wp_delete_attachment( $old, true );
}

// Reintenta productos de Loyverse que siguen sin imagen
function lvs_image_query( $limit ) {
	return [
		'post_type'   => 'product',
		'post_status' => 'publish',
		'fields'      => 'ids',
		'numberposts' => $limit,
		'orderby'     => 'rand',
		'meta_query'  => [
			'relation' => 'AND',
			[ 'key' => '_loyverse_img_src', 'value' => '', 'compare' => '!=' ],
			[
				'relation' => 'OR',
				[ 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_loyverse_img_pending', 'value' => '1' ],
			],
		],
	];
}

function lvs_verify_query( $limit ) {
	return [
		'post_type'   => 'product',
		'post_status' => [ 'publish', 'draft', 'private' ],
		'fields'      => 'ids',
		'numberposts' => $limit,
		'meta_query'  => [
			'relation' => 'AND',
			[ 'key' => '_loyverse_img_src', 'value' => '', 'compare' => '!=' ],
			[ 'key' => '_loyverse_img_verified', 'compare' => 'NOT EXISTS' ],
		],
	];
}

// Detecta imágenes "fantasma": el producto apunta a una imagen cuyo archivo no existe o está corrupto
function lvs_verify_images( $limit = 300 ) {
	global $lvs_deadline;
	foreach ( get_posts( lvs_verify_query( $limit ) ) as $id ) {
		if ( $lvs_deadline && time() >= $lvs_deadline ) break;
		$tid  = (int) get_post_thumbnail_id( $id );
		$file = $tid ? get_attached_file( $tid ) : '';
		$ok   = $file && file_exists( $file ) && filesize( $file ) > 0 && @getimagesize( $file );
		if ( ! $ok ) {
			delete_post_meta( $id, '_thumbnail_id' );
			update_post_meta( $id, '_loyverse_img_pending', '1' );
		}
		update_post_meta( $id, '_loyverse_img_verified', '1' );
	}
}

function lvs_images_remaining() {
	return count( get_posts( lvs_image_query( -1 ) ) ) + count( get_posts( lvs_verify_query( -1 ) ) );
}

function lvs_fix_images( $limit = 60 ) {
	global $lvs_deadline;
	lvs_verify_images( 300 );
	$ids = get_posts( lvs_image_query( $limit ) );
	$i   = 0;
	$n   = count( $ids );
	foreach ( $ids as $id ) {
		if ( $lvs_deadline && time() >= $lvs_deadline ) break;
		lvs_progress( 'images', ++$i, $n );
		lvs_maybe_image( $id, get_post_meta( $id, '_loyverse_img_src', true ), get_the_title( $id ) );
	}
}

/* =========================================================
 * Inventario (Loyverse → Woo)
 * ======================================================= */
function lvs_sync_inventory() {
	global $wpdb, $lvs_deadline;
	$store = lvs_store_id();
	if ( ! $store ) return;

	$last   = get_option( 'lvs_inv_last', '' );
	$cursor = get_option( 'lvs_inv_cursor', '' );
	// Si queda una pasada a medias, conserva su hora de inicio; si no, empieza una nueva
	$started = $cursor ? get_option( 'lvs_inv_started', lvs_iso( time() - 120 ) ) : lvs_iso( time() - 120 );

	$map  = [];
	$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_loyverse_variant_id'" );
	foreach ( $rows as $r ) $map[ $r->meta_value ] = (int) $r->post_id;
	if ( ! $map ) return;

	$count = 0;
	lvs_progress( 'inventory', 0, null );
	do {
		$args = [ 'store_id' => $store, 'limit' => 100 ];
		if ( $last ) $args['updated_at_min'] = $last;
		if ( $cursor ) $args['cursor'] = $cursor;

		$inv = lvs_get( '/inventory', $args );
		if ( ! is_array( $inv ) ) return;

		foreach ( ( isset( $inv['inventory_levels'] ) ? $inv['inventory_levels'] : [] ) as $l ) {
			$count++;
			if ( $count % 25 === 0 ) lvs_progress( 'inventory', $count, null );
			$pid = isset( $map[ $l['variant_id'] ] ) ? $map[ $l['variant_id'] ] : 0;
			if ( ! $pid ) continue;
			$p = wc_get_product( $pid );
			if ( ! $p || ! $p->get_manage_stock() ) continue;
			$qty = (float) $l['in_stock'];
			if ( $p->get_stock_quantity() !== null && (int) $p->get_stock_quantity() === (int) $qty ) continue;
			wc_update_product_stock( $p, $qty, 'set' );
		}
		$cursor = isset( $inv['cursor'] ) ? $inv['cursor'] : '';
		if ( $cursor ) {
			update_option( 'lvs_inv_cursor', $cursor, false );
			update_option( 'lvs_inv_started', $started, false );
		}
	} while ( $cursor && time() < $lvs_deadline );

	if ( ! $cursor ) {
		delete_option( 'lvs_inv_cursor' );
		delete_option( 'lvs_inv_started' );
		update_option( 'lvs_inv_last', $started, false );
	}
}

/* =========================================================
 * Órdenes Woo → Recibos Loyverse
 * ======================================================= */
add_action( 'woocommerce_order_status_processing', 'lvs_send_order' );
add_action( 'woocommerce_order_status_completed', 'lvs_send_order' );

function lvs_send_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_loyverse_receipt_id' ) ) return;

	$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
	if ( $created < (int) get_option( 'lvs_activated_at', 0 ) ) return; // no envía órdenes históricas

	$lock = 'lvs_o_' . $order_id;
	if ( get_transient( $lock ) ) return;
	set_transient( $lock, 1, 60 );

	$lines   = [];
	$total   = 0;
	$skipped = [];
	foreach ( $order->get_items() as $it ) {
		$p   = $it->get_product();
		$vid = $p ? $p->get_meta( '_loyverse_variant_id' ) : '';
		$qty = (float) $it->get_quantity();
		if ( ! $vid || $qty <= 0 ) { $skipped[] = $it->get_name(); continue; }
		$line  = (float) $it->get_total() + (float) $it->get_total_tax(); // precio final con impuestos
		$price = round( $line / $qty, 2 );
		$lines[] = [ 'variant_id' => $vid, 'quantity' => $qty, 'price' => $price ];
		$total  += $price * $qty;
	}

	if ( ! $lines ) {
		$order->update_meta_data( '_loyverse_receipt_id', 'N/A' );
		$order->add_order_note( 'Loyverse: ninguna línea tiene variante de Loyverse; no se envió recibo.' );
		$order->save();
		delete_transient( $lock );
		return;
	}

	$store = lvs_store_id();
	$pay   = lvs_payment_type_id();
	if ( ! $store || ! $pay ) {
		lvs_log( "Orden #$order_id: falta store_id o payment_type_id", 'error' );
		delete_transient( $lock );
		return;
	}

	$body = [
		'store_id'     => $store,
		'order'        => 'WEB-' . $order->get_order_number(),
		'source'       => 'Sitio web',
		'receipt_date' => lvs_iso( $created ),
		'note'         => 'Orden web #' . $order->get_order_number() . ' - ' . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		'line_items'   => $lines,
		'payments'     => [ [ 'payment_type_id' => $pay, 'money_amount' => round( $total, 2 ) ] ],
	];

	$r = lvs_request( 'POST', '/receipts', [], $body );
	if ( $r['ok'] ) {
		$num = isset( $r['data']['receipt_number'] ) ? $r['data']['receipt_number'] : 'ok';
		$order->update_meta_data( '_loyverse_receipt_id', $num );
		$order->delete_meta_data( '_lvs_receipt_error' );
		$note = 'Loyverse: recibo ' . $num . ' creado.';
		if ( $skipped ) $note .= ' Sin vincular (no enviados): ' . implode( ', ', $skipped ) . '.';
		$order->add_order_note( $note );
	} else {
		$order->update_meta_data( '_lvs_receipt_error', wp_json_encode( isset( $r['data'] ) ? $r['data'] : 'error' ) );
		$order->add_order_note( 'Loyverse: error al crear recibo (se reintenta automáticamente). Ver registros loyverse-sync.' );
	}
	$order->save();
	delete_transient( $lock );
}

function lvs_retry_orders() {
	lvs_progress( 'orders' );
	$ids = wc_get_orders( [
		'status'       => [ 'processing', 'completed' ],
		'limit'        => 10,
		'return'       => 'ids',
		'date_created' => '>' . (int) get_option( 'lvs_activated_at', 0 ),
		'meta_query'   => [ [ 'key' => '_loyverse_receipt_id', 'compare' => 'NOT EXISTS' ] ],
	] );
	foreach ( $ids as $oid ) lvs_send_order( $oid );
}

/* =========================================================
 * Panel admin: WooCommerce → Loyverse Sync
 * ======================================================= */
add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Loyverse Sync', 'Loyverse Sync', 'manage_woocommerce', 'loyverse-sync', 'lvs_admin_page' );
} );

add_action( 'admin_post_lvs_do', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado' );
	check_admin_referer( 'lvs_do' );
	global $lvs_deadline;
	@set_time_limit( 280 );
	$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
	if ( $do === 'full' ) {
		lvs_run_sync( true );
	} elseif ( $do === 'images' ) {
		$lvs_deadline = time() + 200;
		lvs_fix_images( 40 );
	} else {
		lvs_run_sync();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=loyverse-sync&done=1' ) );
	exit;
} );

add_action( 'wp_ajax_lvs_status', function () {
	check_ajax_referer( 'lvs_ajax' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No autorizado' );
	wp_send_json_success( lvs_progress_payload() );
} );

add_action( 'wp_ajax_lvs_run', function () {
	check_ajax_referer( 'lvs_ajax' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No autorizado' );
	global $lvs_deadline;
	$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'sync';

	if ( $mode === 'images' ) {
		if ( get_transient( 'lvs_lock' ) ) wp_send_json_success( [ 'busy' => true ] );
		set_transient( 'lvs_lock', 1, 200 );
		@set_time_limit( 120 );
		$lvs_deadline = time() + 25;
		lvs_fix_images( 60 );
		delete_transient( 'lvs_lock' );
		lvs_progress( 'done', null, null, false );
		wp_send_json_success( [ 'more' => false, 'remaining' => lvs_images_remaining() ] );
	}

	// Lotes cortos (30 s) para no chocar con el límite de tiempo del hosting
	$ran = lvs_run_sync( $mode === 'full', 30 );
	if ( ! $ran ) wp_send_json_success( [ 'busy' => true ] );
	$st = get_option( 'lvs_state', [] );
	$vs = get_option( 'lvs_var_state', [] );
	wp_send_json_success( [
		'more'      => empty( $st['full_done'] ) || (bool) get_option( 'lvs_inv_cursor', '' )
			|| ! empty( $vs['active'] ) || (bool) get_option( 'lvs_reconcile_pending', null ),
		'remaining' => lvs_images_remaining(),
	] );
} );

add_action( 'admin_post_lvs_save_token', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado' );
	check_admin_referer( 'lvs_save_token' );
	$t = isset( $_POST['lvs_token'] ) ? trim( wp_unslash( $_POST['lvs_token'] ) ) : '';
	if ( $t !== '' ) {
		update_option( 'lvs_token_opt', $t, true );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'lvs_token_opt', 'options' );
		delete_option( 'lvs_store_id' );
		delete_option( 'lvs_payment_type_id' );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=loyverse-sync&saved=1' ) );
	exit;
} );

function lvs_admin_page() {
	global $wpdb;
	$st      = wp_parse_args( get_option( 'lvs_state', [] ), [ 'full_done' => false, 'last_sync' => '' ] );
	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_loyverse_id' AND p.post_status<>'trash' AND p.post_type='product'" );
	$noimg   = lvs_images_remaining();
	$noimg_lv = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_loyverse_img_src' AND pm.meta_value='' AND p.post_status<>'trash' AND p.post_type='product'" );
	$pending = count( wc_get_orders( [
		'status' => [ 'processing', 'completed' ], 'limit' => -1, 'return' => 'ids',
		'date_created' => '>' . (int) get_option( 'lvs_activated_at', 0 ),
		'meta_query' => [ [ 'key' => '_loyverse_receipt_id', 'compare' => 'NOT EXISTS' ] ],
	] ) );
	?>
	<div class="wrap lvs-wrap">
		<style>
.lvs-wrap{max-width:1100px;margin:20px 20px 0 2px;--lvs-ink:#0f172a;--lvs-mut:#646970;--lvs-line:#e5e7eb;--lvs-blue:#2271b1;--lvs-ok:#00a32a;--lvs-warn:#d63638}
.lvs-wrap *{box-sizing:border-box}
.lvs-hero{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#0f172a 0%,#1d4e79 55%,#2271b1 100%);color:#fff;border-radius:14px;padding:24px 26px;box-shadow:0 8px 24px rgba(15,23,42,.18)}
.lvs-hero h1{color:#fff;font-size:26px;line-height:1.2;margin:0 0 6px;font-weight:700}
.lvs-hero p{color:#dbeafe;margin:0;font-size:13.5px;max-width:620px}
.lvs-hero-side{display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.lvs-badge{display:inline-block;background:#fff;color:#0f172a;font-weight:700;font-size:12px;border-radius:999px;padding:5px 12px}
.lvs-pill{display:inline-block;font-size:12px;font-weight:600;border-radius:999px;padding:5px 12px;background:rgba(255,255,255,.16);color:#fff}
.lvs-pill-ok{background:#00a32a;color:#fff}.lvs-pill-bad{background:#d63638;color:#fff}
.lvs-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:16px 0}
.lvs-card{background:#fff;border:1px solid var(--lvs-line);border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
.lvs-card .lvs-num{font-size:30px;font-weight:700;color:var(--lvs-ink);line-height:1.1}
.lvs-card .lvs-lbl{color:var(--lvs-mut);font-size:12.5px;margin-top:4px}
.lvs-card .lvs-ico{font-size:18px;margin-bottom:8px}
.lvs-grid{display:grid;grid-template-columns:1.25fr .85fr;gap:14px;align-items:start}
@media(max-width:900px){.lvs-grid{grid-template-columns:1fr}.lvs-hero-side{align-items:flex-start}}
.lvs-panel{background:#fff;border:1px solid var(--lvs-line);border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(15,23,42,.06);margin-bottom:14px}
.lvs-panel h2{margin:0 0 6px;font-size:16px;color:var(--lvs-ink)}
.lvs-panel .lvs-sub{color:var(--lvs-mut);font-size:12.5px;margin:0 0 14px}
.lvs-rows{margin:0}.lvs-row{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #f0f0f1}
.lvs-row:last-child{border-bottom:0}.lvs-row dt{color:var(--lvs-mut)}.lvs-row dd{margin:0;font-weight:600;color:var(--lvs-ink);text-align:right}
.lvs-actions{display:flex;flex-direction:column;gap:9px;margin-top:6px}
.lvs-actions .button{border-radius:8px;padding:5px 14px;text-align:center}
.lvs-token-form{display:flex;flex-wrap:wrap;gap:9px;align-items:center}
.lvs-token-form .regular-text{flex:1 1 320px;border-radius:8px;min-height:36px}
.lvs-token-form .button{border-radius:8px;min-height:36px}
#lvs-prog{margin:4px 0 14px}
#lvs-label{margin-bottom:7px;font-weight:600;color:var(--lvs-ink)}
.lvs-track{background:#e5e7eb;border-radius:999px;height:12px;overflow:hidden}
#lvs-bar{height:100%;width:0;background:linear-gradient(90deg,#2271b1,#72aee6);border-radius:999px;transition:width .4s}
#lvs-bar.lvs-ind{background:repeating-linear-gradient(45deg,#2271b1,#2271b1 10px,#72aee6 10px,#72aee6 20px)!important;background-size:28px 28px;animation:lvsmove .8s linear infinite}
@keyframes lvsmove{from{background-position:0 0}to{background-position:28px 0}}
.lvs-wrap .notice{border-radius:8px;margin:14px 0 0}
</style>
		<header class="lvs-hero">
			<div>
				<h1>Loyverse Sync</h1>
				<p>Sincroniza Loyverse con WooCommerce: productos, variantes, im&aacute;genes, inventario, eliminaciones y &oacute;rdenes &rarr; recibos.</p>
			</div>
			<div class="lvs-hero-side">
				<span class="lvs-badge">Versi&oacute;n 1.1.2</span>
				<?php if ( lvs_token() ) : ?><span class="lvs-pill lvs-pill-ok">Token configurado</span><?php else : ?><span class="lvs-pill lvs-pill-bad">Falta el token</span><?php endif; ?>
			</div>
		</header>
		<?php if ( isset( $_GET['done'] ) ) echo '<div class="notice notice-success"><p>Listo. Revisa WooCommerce → Estado → Registros → loyverse-sync.</p></div>'; ?>
		<?php if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success"><p>Token guardado.</p></div>'; ?>
		<section class="lvs-cards">
			<div class="lvs-card"><div class="lvs-ico">&#128230;</div><div class="lvs-num"><?php echo (int) $total; ?></div><div class="lvs-lbl">Productos vinculados</div></div>
			<div class="lvs-card"><div class="lvs-ico">&#129534;</div><div class="lvs-num"><?php echo (int) $pending; ?></div><div class="lvs-lbl">&Oacute;rdenes pendientes de enviar</div></div>
			<div class="lvs-card"><div class="lvs-ico">&#128247;</div><div class="lvs-num"><?php echo (int) $noimg; ?></div><div class="lvs-lbl">Im&aacute;genes pendientes de descargar/verificar</div></div>
			<div class="lvs-card"><div class="lvs-ico">&#128444;</div><div class="lvs-num"><?php echo (int) $noimg_lv; ?></div><div class="lvs-lbl">Productos sin imagen en Loyverse</div></div>
		</section>

		<div class="lvs-grid">
			<section class="lvs-panel">
				<h2>Estado de la sincronizaci&oacute;n</h2>
				<p class="lvs-sub">Resumen actual de la conexi&oacute;n y de las reconciliaciones.</p>
				<dl class="lvs-rows">
					<div class="lvs-row"><dt>Token configurado</dt><dd><?php echo lvs_token() ? 'Sí' : '<b style="color:#b32d2e">NO (pégalo abajo y guarda)</b>'; ?></dd></div>
					<div class="lvs-row"><dt>Pasada completa</dt><dd><?php echo $st['full_done'] ? 'Terminada' : 'En progreso (continúa cada 10 min)'; ?></dd></div>
					<div class="lvs-row"><dt>Última sincronización</dt><dd><?php echo esc_html( $st['last_sync'] ? $st['last_sync'] : '—' ); ?></dd></div>
					<div class="lvs-row"><dt>Productos vinculados</dt><dd><?php echo (int) $total; ?></dd></div>
					<div class="lvs-row"><dt>Imágenes pendientes de descargar/verificar</dt><dd><?php echo (int) $noimg; ?></dd></div>
					<div class="lvs-row"><dt>Productos sin imagen en Loyverse</dt><dd><?php echo (int) $noimg_lv; ?></dd></div>
					<div class="lvs-row"><dt>Órdenes pendientes de enviar</dt><dd><?php echo (int) $pending; ?></dd></div>
					<div class="lvs-row"><dt>Versión del plugin</dt><dd>1.1.2</dd></div>
					<div class="lvs-row"><dt>Reconciliación de productos pendiente</dt><dd><?php echo get_option( 'lvs_reconcile_pending', null ) ? '<b style="color:#b32d2e">SÍ (se reintenta en la próxima sincronización)</b>' : 'No'; ?></dd></div>
					<div class="lvs-row"><dt>Última reconciliación de variaciones (/variants)</dt><dd><?php $vl = (int) get_option( 'lvs_var_last', 0 ); echo $vl ? esc_html( date_i18n( 'Y-m-d H:i:s', $vl ) ) : '—'; ?></dd></div>
					<div class="lvs-row"><dt>Barrido de variaciones en curso</dt><dd><?php $vs2 = get_option( 'lvs_var_state', [] ); echo ! empty( $vs2['active'] ) ? 'Sí (se reanuda automáticamente)' : 'No'; ?></dd></div>
				</dl>
			</section>
			<div>
				<section class="lvs-panel">
					<h2>Acciones</h2>
					<p class="lvs-sub">Ejecuta una sincronizaci&oacute;n o repara las im&aacute;genes faltantes.</p>
					<div id="lvs-prog" style="display:none">
						<div id="lvs-label"></div>
						<div class="lvs-track"><div id="lvs-bar"></div></div>
					</div>
					<div class="lvs-actions">
						<button type="button" class="button button-primary lvs-run" data-mode="sync">Sincronizar ahora</button>
						<button type="button" class="button lvs-run" data-mode="images">Reintentar imágenes faltantes</button>
						<button type="button" class="button lvs-run" data-mode="full">Resincronización completa</button>
					</div>
				</section>
				<section class="lvs-panel">
					<h2>Token de Loyverse</h2>
					<p class="lvs-sub">Pega un token nuevo para reemplazar el guardado.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lvs-token-form">
						<input type="hidden" name="action" value="lvs_save_token">
						<?php wp_nonce_field( 'lvs_save_token' ); ?>
						<input type="password" name="lvs_token" autocomplete="off" class="regular-text" placeholder="<?php echo lvs_token() ? esc_attr( 'Guardado: ••••' . substr( lvs_token(), -4 ) . ' (pega uno nuevo para reemplazarlo)' ) : 'Pega aquí el token de Loyverse'; ?>">
						<button class="button">Guardar token</button>
					</form>
				</section>
			</div>
		</div>
		<script>
		(function () {
			var NONCE = '<?php echo esc_js( wp_create_nonce( 'lvs_ajax' ) ); ?>';
			var manual = false, wasRunning = false;
			function $(id) { return document.getElementById(id); }
			function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
			function post(action, data) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('_wpnonce', NONCE);
				for (var k in (data || {})) fd.append(k, data[k]);
				return fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.text(); })
					.then(function (t) {
						try { return JSON.parse(t); }
						catch (e) { throw new Error('El servidor respondió con una página de error (posible timeout)'); }
					});
			}
			function setBtns(dis) {
				var b = document.querySelectorAll('.lvs-run');
				for (var i = 0; i < b.length; i++) b[i].disabled = dis;
			}
			function render(p, active) {
				var box = $('lvs-prog'), bar = $('lvs-bar'), lab = $('lvs-label');
				var recent = !p.running && p.phase === 'done' && (p.now - p.ts) < 20;
				if (!active && !manual && !recent) { box.style.display = 'none'; return; }
				box.style.display = 'block';
				if (recent && !manual) {
					bar.className = '';
					bar.style.background = '#00a32a';
					bar.style.width = '100%';
					lab.textContent = 'Sincronización completada ✓';
					return;
				}
				bar.style.background = '#2271b1';
				if (active && p.pct !== null && p.pct !== undefined) {
					bar.className = '';
					bar.style.width = p.pct + '%';
				} else {
					bar.className = 'lvs-ind';
					bar.style.width = '100%';
				}
				var txt = 'Procesando…';
				if (active) {
					txt = 'Sincronizando… ' + p.label;
					if (p.done !== null && p.done !== undefined) txt += ': ' + p.done + (p.total ? ' / ' + p.total : '');
					if (p.pct !== null && p.pct !== undefined) txt += ' (' + p.pct + '%)';
				}
				lab.textContent = txt;
			}
			function poll() {
				post('lvs_status').then(function (r) {
					if (!r.success) return;
					var p = r.data, active = p.running && (p.now - p.ts) < 120;
					render(p, active);
					if (active) wasRunning = true;
					else if (wasRunning && !manual) { wasRunning = false; setTimeout(function () { location.reload(); }, 2500); }
				}).catch(function () {});
			}
			async function run(mode) {
				if (manual) return;
				if (mode === 'full' && !confirm('Reinicia la pasada completa (productos, imágenes y eliminaciones). ¿Continuar?')) return;
				manual = true;
				setBtns(true);
				$('lvs-prog').style.display = 'block';
				$('lvs-label').textContent = 'Iniciando…';
				$('lvs-bar').className = 'lvs-ind';
				$('lvs-bar').style.width = '100%';
				var m = mode, tries = 0, fails = 0, prevRem = null, err = '';
				while (true) {
					var r;
					try {
						r = await post('lvs_run', { mode: m });
					} catch (e) {
						// Timeout o página de error: espera a que termine el lote anterior y reintenta
						if (++fails > 6) { err = 'Error de conexión: ' + e.message; break; }
						$('lvs-label').textContent = 'Reintentando… (' + fails + '/6)';
						await sleep(8000);
						continue;
					}
					fails = 0;
					if (!r.success) { err = r.data || 'Error'; break; }
					if (r.data.busy) { if (++tries > 30) break; await sleep(4000); continue; }
					tries = 0;
					if (m === 'full') m = 'sync';
					var rem = (r.data.remaining === undefined) ? 0 : r.data.remaining;
					var imgProgress = rem > 0 && (prevRem === null || rem < prevRem);
					prevRem = rem;
					if (!r.data.more && !imgProgress) break;
				}
				manual = false;
				if (err) { setBtns(false); alert(err); return; }
				setTimeout(function () { location.reload(); }, 1200);
			}
			var btns = document.querySelectorAll('.lvs-run');
			for (var i = 0; i < btns.length; i++) {
				btns[i].addEventListener('click', function () { run(this.getAttribute('data-mode')); });
			}
			setInterval(poll, 2000);
			poll();
		})();
		</script>
	</div>
	<?php
}

