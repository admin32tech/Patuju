<?php

declare(strict_types=1);

namespace Patuju\Tests\Integration;

use Patuju\Tests\Support\TestCase;

final class ProductosTest extends TestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->assertSame(401, $this->get('/ajax/productos.php')->status);
    }

    public function testReturnsActiveAvailableProductsGroupedByCategory(): void
    {
        $this->loginAs('caja_caja_central');

        $response = $this->get('/ajax/productos.php', 'caja_caja_central');

        $this->assertSame(200, $response->status, $response->body);
        $data = $response->json();
        $this->assertTrue($data['success']);
        $this->assertCount(3, $data['data']);

        $total = 0;
        foreach ($data['data'] as $categoria) {
            $this->assertNotEmpty($categoria['productos']);
            $total += count($categoria['productos']);
        }
        $this->assertSame(16, $total);

        $producto = $this->findProduct($data['data'], 1);
        $this->assertNotNull($producto);
        $this->assertSame('Salteña Grande', $producto['nombre']);
        $this->assertSame('7.00', $producto['precio']);
        $this->assertSame('7.00', $producto['precio_base']);
        $this->assertSame(80, (int) $producto['cantidad_disponible']);
    }

    public function testPausedProductsAreExcluded(): void
    {
        $this->loginAs('caja_caja_central');
        $this->db()->exec('UPDATE stock_sucursal SET disponible_venta = 0 WHERE sucursal_id = 1 AND producto_id = 1');

        $response = $this->get('/ajax/productos.php', 'caja_caja_central');

        $data = $response->json()['data'];
        $this->assertNull($this->findProduct($data, 1));
        $this->assertSame(15, $this->countProducts($data));
    }

    public function testInactiveProductsAreExcluded(): void
    {
        $this->loginAs('caja_caja_central');
        $this->db()->exec('UPDATE productos SET activo = 0 WHERE id = 2');

        $response = $this->get('/ajax/productos.php', 'caja_caja_central');

        $data = $response->json()['data'];
        $this->assertNull($this->findProduct($data, 2));
        $this->assertSame(15, $this->countProducts($data));
    }

    public function testBranchSpecificPriceIsReturned(): void
    {
        $this->loginAs('caja_caja_central');
        $this->db()->exec('UPDATE stock_sucursal SET precio_sucursal = 9.50 WHERE sucursal_id = 1 AND producto_id = 1');

        $response = $this->get('/ajax/productos.php', 'caja_caja_central');

        $producto = $this->findProduct($response->json()['data'], 1);
        $this->assertNotNull($producto);
        $this->assertSame('9.50', $producto['precio']);
        $this->assertSame('9.50', $producto['precio_sucursal']);
        $this->assertSame('7.00', $producto['precio_base']);
    }

    public function testCajaOnlySeesOwnBranchStock(): void
    {
        $this->db()->exec('UPDATE stock_sucursal SET cantidad_disponible = 5 WHERE sucursal_id = 2 AND producto_id = 1');

        $this->loginAs('caja_caja_central');
        $central = $this->findProduct($this->get('/ajax/productos.php', 'caja_caja_central')->json()['data'], 1);
        $this->assertNotNull($central);
        $this->assertSame(80, (int) $central['cantidad_disponible']);

        $this->loginAs('caja_caja_lujan');
        $lujan = $this->findProduct($this->get('/ajax/productos.php', 'caja_caja_lujan')->json()['data'], 1);
        $this->assertNotNull($lujan);
        $this->assertSame(5, (int) $lujan['cantidad_disponible']);
    }

    /**
     * @param list<array<string, mixed>> $categorias
     * @return array<string, mixed>|null
     */
    private function findProduct(array $categorias, int $productoId): ?array
    {
        foreach ($categorias as $categoria) {
            foreach ($categoria['productos'] as $producto) {
                if ((int) $producto['id'] === $productoId) {
                    return $producto;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $categorias
     */
    private function countProducts(array $categorias): int
    {
        return array_sum(array_map(
            static fn (array $categoria): int => count($categoria['productos']),
            $categorias
        ));
    }
}
