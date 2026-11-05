<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class CrudProductosTest extends TestCase
{
    public function testListRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/crud_productos.php')->status);
    }

    public function testListReturnsProductsAndCategories(): void
    {
        $this->loginAs('admin');

        $response = $this->get('/ajax/crud_productos.php', 'admin');

        $this->assertSame(200, $response->status);
        $data = $response->json();
        $this->assertCount(16, $data['productos']);
        $this->assertCount(4, $data['categorias']);
        $this->assertSame('Salteña Grande', $data['productos'][0]['nombre']);
    }

    public function testNonAdminCannotModifyProducts(): void
    {
        $this->loginAs('caja_caja_central');

        $create = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Intruso',
            'precio' => 10,
            'categoria_id' => 1,
        ], 'caja_caja_central');
        $this->assertSame(403, $create->status);

        $update = $this->put('/ajax/crud_productos.php?id=1', [
            'nombre' => 'Hackeado',
            'precio' => 1,
            'categoria_id' => 1,
        ], 'caja_caja_central');
        $this->assertSame(403, $update->status);

        $delete = $this->delete('/ajax/crud_productos.php?id=1', 'caja_caja_central');
        $this->assertSame(403, $delete->status);

        $this->assertSame('Salteña Grande', $this->scalar('SELECT nombre FROM productos WHERE id = 1'));
        $this->assertSame(1, (int) $this->scalar('SELECT activo FROM productos WHERE id = 1'));
    }

    public function testCreateProductWithInitialStock(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Producto de Prueba',
            'precio' => 12.50,
            'categoria_id' => 1,
            'tipo_rotacion' => 'diaria',
            'descripcion' => 'Creado por la suite',
            'stock_inicial' => 7,
            'alerta_minima' => 3,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success']);
        $id = (int) $data['id'];

        $producto = $this->row('SELECT * FROM productos WHERE id = ?', [$id]);
        $this->assertNotNull($producto);
        $this->assertSame('Producto de Prueba', $producto['nombre']);
        $this->assertSame('12.50', $producto['precio']);
        $this->assertSame('diaria', $producto['tipo_rotacion']);

        $stocks = $this->rows('SELECT * FROM stock_sucursal WHERE producto_id = ?', [$id]);
        $this->assertCount(16, $stocks);
        foreach ($stocks as $stock) {
            $this->assertSame(7, (int) $stock['cantidad_disponible']);
            $this->assertSame(3, (int) $stock['alerta_minima']);
        }

        $this->assertSame(
            16,
            (int) $this->scalar("SELECT COUNT(*) FROM stock_movimientos WHERE producto_id = ? AND tipo_movimiento = 'ingreso_lote'", [$id])
        );
    }

    public function testCreateRequiresMandatoryFields(): void
    {
        $this->loginAs('admin');

        $sinNombre = $this->post('/ajax/crud_productos.php', [
            'precio' => 10,
            'categoria_id' => 1,
        ], 'admin');
        $this->assertSame(400, $sinNombre->status);

        $sinPrecio = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Sin precio',
            'categoria_id' => 1,
        ], 'admin');
        $this->assertSame(400, $sinPrecio->status);

        $sinCategoria = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Sin categoría',
            'precio' => 10,
        ], 'admin');
        $this->assertSame(400, $sinCategoria->status);

        $this->assertSame(16, (int) $this->scalar('SELECT COUNT(*) FROM productos'));
    }

    public function testCreateRejectsInvalidPrice(): void
    {
        $this->loginAs('admin');

        foreach (['abc', 0, -5] as $precio) {
            $response = $this->post('/ajax/crud_productos.php', [
                'nombre' => 'Precio inválido',
                'precio' => $precio,
                'categoria_id' => 1,
            ], 'admin');
            $this->assertSame(400, $response->status, 'Precio rechazado: ' . var_export($precio, true));
        }

        $this->assertSame(16, (int) $this->scalar('SELECT COUNT(*) FROM productos'));
    }

    public function testCreateRejectsInvalidRotationType(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Rotación inválida',
            'precio' => 10,
            'categoria_id' => 1,
            'tipo_rotacion' => 'mensual',
        ], 'admin');

        $this->assertSame(400, $response->status);
        $this->assertSame(16, (int) $this->scalar('SELECT COUNT(*) FROM productos'));
    }

    public function testCreateRejectsUnknownCategory(): void
    {
        $this->loginAs('admin');

        $response = $this->post('/ajax/crud_productos.php', [
            'nombre' => 'Categoría inexistente',
            'precio' => 10,
            'categoria_id' => 999,
        ], 'admin');

        $this->assertSame(400, $response->status);
        $this->assertSame(16, (int) $this->scalar('SELECT COUNT(*) FROM productos'));
    }

    public function testUpdateChangesProduct(): void
    {
        $this->loginAs('admin');

        $response = $this->put('/ajax/crud_productos.php?id=1', [
            'nombre' => 'Salteña Grande XL',
            'precio' => 7.50,
            'categoria_id' => 1,
            'tipo_rotacion' => 'diaria',
            'descripcion' => 'Actualizada',
            'imagen' => 'default.png',
            'activo' => 1,
        ], 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $producto = $this->row('SELECT * FROM productos WHERE id = 1');
        $this->assertNotNull($producto);
        $this->assertSame('Salteña Grande XL', $producto['nombre']);
        $this->assertSame('7.50', $producto['precio']);
        $this->assertSame('Actualizada', $producto['descripcion']);
    }

    public function testUpdateRequiresMandatoryFields(): void
    {
        $this->loginAs('admin');

        $response = $this->put('/ajax/crud_productos.php?id=1', [], 'admin');

        $this->assertSame(400, $response->status);
        $this->assertSame('Salteña Grande', $this->scalar('SELECT nombre FROM productos WHERE id = 1'));
    }

    public function testUpdateRejectsInvalidPriceAndRotation(): void
    {
        $this->loginAs('admin');

        $badPrice = $this->put('/ajax/crud_productos.php?id=1', [
            'nombre' => 'Salteña Grande',
            'precio' => -1,
            'categoria_id' => 1,
        ], 'admin');
        $this->assertSame(400, $badPrice->status);

        $badRotation = $this->put('/ajax/crud_productos.php?id=1', [
            'nombre' => 'Salteña Grande',
            'precio' => 7,
            'categoria_id' => 1,
            'tipo_rotacion' => 'quincenal',
        ], 'admin');
        $this->assertSame(400, $badRotation->status);

        $this->assertSame('7.00', $this->scalar('SELECT precio FROM productos WHERE id = 1'));
        $this->assertSame('diaria', $this->scalar('SELECT tipo_rotacion FROM productos WHERE id = 1'));
    }

    public function testUpdateUnknownProductReturnsNotFound(): void
    {
        $this->loginAs('admin');

        $response = $this->put('/ajax/crud_productos.php?id=9999', [
            'nombre' => 'Fantasma',
            'precio' => 10,
            'categoria_id' => 1,
        ], 'admin');

        $this->assertSame(404, $response->status);
    }

    public function testUpdateAndDeleteRequireId(): void
    {
        $this->loginAs('admin');

        $update = $this->put('/ajax/crud_productos.php', [
            'nombre' => 'Sin ID',
            'precio' => 10,
            'categoria_id' => 1,
        ], 'admin');
        $this->assertSame(400, $update->status);

        $delete = $this->delete('/ajax/crud_productos.php', 'admin');
        $this->assertSame(400, $delete->status);
    }

    public function testDeleteSoftDeletesProduct(): void
    {
        $this->loginAs('admin');

        $response = $this->delete('/ajax/crud_productos.php?id=2', 'admin');

        $this->assertSame(200, $response->status, $response->body);
        $this->assertSame(0, (int) $this->scalar('SELECT activo FROM productos WHERE id = 2'));
        $this->assertSame(60, $this->stock(1, 2));

        $list = $this->get('/ajax/crud_productos.php', 'admin')->json()['productos'];
        $this->assertCount(16, $list);
    }

    public function testDeleteUnknownProductReturnsNotFound(): void
    {
        $this->loginAs('admin');

        $response = $this->delete('/ajax/crud_productos.php?id=9999', 'admin');

        $this->assertSame(404, $response->status);
    }
}
