# PrestaShop Cleaner

Módulo complementario no oficial. El módulo oficial de PrestaShop está archivado y ya no se mantiene. Esta versión sigue el mismo módulo para poder usarlo en PrestaShop 8 y 9, incluida la 9.2, y en PHP 8.4, sin dejar de funcionar en tiendas anteriores.

- Módulo oficial (archivado): https://github.com/prestashop/pscleaner
- Módulo actualizado: https://github.com/cloyola/pscleaner

## Qué hace

Permite, desde el back office, revisar y corregir datos huérfanos, limpiar la base de datos y vaciar el catálogo o los pedidos y clientes. No hay vuelta atrás después de vaciar datos.

## Compatibilidad

- PrestaShop 1.7.1.0 a 9.x (incluida la 9.2)
- PHP 7.1 a 8.4
- No es compatible con multitienda: la acción afecta a todas las tiendas

## Cambios en 2.2.0

- El módulo carga en una instalación nueva de PrestaShop 9. La guarda usa `_PS_VERSION_` en lugar de `_CAN_LOAD_FILES_`, que ya no existe.
- `Tools::encrypt()` se sustituye por `Tools::hash()`, porque el método antiguo se eliminó en PrestaShop 9.
- Las consultas comprueban que la tabla y la columna existan. Si una versión no las tiene, se omiten y el proceso continúa.
- Se tienen en cuenta tablas que desaparecieron en PrestaShop 8 (`attribute_impact`, `referrer_cache`) y tablas nuevas de PrestaShop 9 (`shipment`, `shipment_product`, `customer_session`, `product_attribute_lang` y las relaciones B2B del cliente).
- Las imágenes de producto, categoría, fabricante y proveedor se borran también en png, webp y avif. En PrestaShop 9 se usa `_PS_PRODUCT_IMG_DIR_`.
- El stock compartido (`id_shop = 0`) ya no se borra como si fuera un dato huérfano.
- La configuración del módulo muestra el aviso de módulo no oficial.

La actualización desde la 2.1.0 no modifica la base de datos. El script `upgrade/upgrade-2.2.0.php` solo marca la versión nueva.

## Cómo actualizar una tienda que ya lo tiene instalado

1. Sustituye la carpeta `modules/pscleaner` por esta versión.
2. En el back office, entra en Módulos y actualiza PrestaShop Cleaner.

PrestaShop ejecutará `upgrade/upgrade-2.2.0.php` y dejará la versión instalada en 2.2.0.
