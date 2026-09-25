# Informe: Integridad y fidelidad de datos en una aplicación PHP + AJAX + JavaScript + Chart.js
# es algo generico tomarlo como concideracion o ejemplo, sin embargo si respeta lo que quiero todo lo que esta aqui
## 1. Objetivo

El objetivo de esta propuesta es establecer una arquitectura para que
una aplicación web pueda mostrar datos **actualizados periódicamente**,
sin necesidad de implementar tiempo real, manteniendo como prioridad la
**fidelidad de la información mostrada**.

La idea central es:

> **La fidelidad depende de la arquitectura y de cómo se controlan los
> datos, no de si el gráfico lo dibuja JavaScript, Chart.js, Python u
> otra biblioteca.**

En este diseño, la base de datos sigue siendo la fuente de verdad. PHP
es responsable de consultar y procesar esa información, AJAX/Fetch
transporta la respuesta, JavaScript administra el estado de la interfaz
y Chart.js se limita a representar visualmente los datos.

------------------------------------------------------------------------

## 2. Decisión: actualización periódica en lugar de tiempo real

Para muchos dashboards y sistemas administrativos no es necesario que
cada cambio de la base de datos aparezca inmediatamente en pantalla.

Un modelo de actualización periódica puede funcionar así:

``` text
Base de datos
      |
      v
    PHP
      |
      v
    JSON
      |
      v
AJAX / Fetch
      |
      v
 JavaScript
      |
      v
  Chart.js
```

Por ejemplo, el navegador puede consultar los datos cada 30 o 60
segundos.

### Ventajas

-   Reduce la cantidad de consultas a PHP y a la base de datos.
-   Reduce tráfico y consumo de recursos.
-   Es mucho más sencillo que implementar WebSockets u otros mecanismos
    de tiempo real.
-   Es más fácil de depurar.
-   Permite establecer una política clara de actualización.
-   Mantiene la arquitectura desacoplada.
-   Permite indicar al usuario cuándo se obtuvo el último dato válido.
-   Permite conservar el último dato correcto cuando una actualización
    falla.

### Punto importante

"Actualización periódica" no significa "mostrar datos sin control".

Significa:

> El sistema acepta una pequeña ventana de antigüedad, pero controla
> explícitamente la antigüedad, el éxito o fallo de cada actualización y
> la procedencia de los datos.

------------------------------------------------------------------------

## 3. El principio más importante: nunca reemplazar datos válidos por un error

Esta debe ser una regla fundamental de la interfaz.

Supongamos que el servidor entrega:

``` text
13:15:00 -> Ventas: 1.520
```

El navegador conserva ese dato.

A las 13:15:30 intenta actualizar:

``` text
JavaScript -> PHP -> Base de datos
```

pero el servidor está temporalmente caído.

La aplicación **NO debe hacer esto**:

``` text
Ventas: 0
```

ni:

``` text
Ventas: ---
```

ni borrar el gráfico y dejarlo aparentemente vacío.

Debe conservar:

``` text
Ventas: 1.520

Estado: No se pudo actualizar
Última actualización correcta: 13:15:00
```

De esta forma se diferencia claramente entre:

-   **dato válido pero antiguo**, y
-   **dato inexistente o desconocido**.

Esta diferencia es crítica.

### Regla propuesta

> **Una actualización fallida nunca debe destruir ni reemplazar el
> último estado válido conocido.**

------------------------------------------------------------------------

## 4. Estados recomendados para los datos

Cada componente que muestre información importante debería poder
distinguir, como mínimo:

``` text
🟢 ACTUALIZADO
🟡 ACTUALIZANDO
🟠 DATOS ANTIGUOS
🔴 ERROR DE ACTUALIZACIÓN
```

Ejemplo:

``` text
Ventas
1.520

🟢 Actualizado
Última actualización: 13:15:00
```

Si falla una consulta:

``` text
Ventas
1.520

🔴 No se pudo actualizar
Último dato válido: 13:15:00
```

Si han pasado demasiados minutos:

``` text
Ventas
1.520

🟠 Datos posiblemente antiguos
Último dato válido: 13:15:00
```

Esto es preferible a ocultar el problema.

------------------------------------------------------------------------

# 5. Arquitectura propuesta

La arquitectura recomendada para el stack actual es:

``` text
                         ┌─────────────────────┐
                         │    BASE DE DATOS    │
                         │                     │
                         │ FUENTE DE VERDAD    │
                         └──────────┬──────────┘
                                    │
                                    v
                         ┌─────────────────────┐
                         │        PHP          │
                         │                     │
                         │ - Autenticación     │
                         │ - Autorización      │
                         │ - Consultas         │
                         │ - Validaciones      │
                         │ - Cálculos          │
                         └──────────┬──────────┘
                                    │
                                    v
                              RESPUESTA JSON
                                    │
                                    v
                         ┌─────────────────────┐
                         │     AJAX / FETCH    │
                         │                     │
                         │ - Transporte        │
                         │ - Control de error  │
                         │ - Tiempo de espera  │
                         └──────────┬──────────┘
                                    │
                                    v
                         ┌─────────────────────┐
                         │    JAVASCRIPT       │
                         │                     │
                         │ - Estado            │
                         │ - Antigüedad        │
                         │ - Actualización     │
                         │ - Interfaz          │
                         └──────────┬──────────┘
                                    │
                                    v
                         ┌─────────────────────┐
                         │      CHART.JS       │
                         │                     │
                         │   VISUALIZACIÓN     │
                         └─────────────────────┘
```

## 5.1 Responsabilidad de cada capa

### Base de datos

Es la fuente de verdad.

Debe proteger la integridad mediante mecanismos como:

-   claves primarias;
-   claves foráneas;
-   restricciones `NOT NULL`;
-   `UNIQUE`;
-   validaciones apropiadas;
-   transacciones;
-   tipos de datos correctos.

### PHP

Es la autoridad del backend.

Debe:

1.  autenticar;
2.  autorizar;
3.  consultar la base de datos;
4.  realizar cálculos;
5.  validar resultados;
6.  generar una respuesta estructurada.

JavaScript no debe tener autoridad sobre lo que existe realmente en la
base de datos.

### AJAX / Fetch

Es el transporte entre navegador y servidor.

Debe comprobar que la respuesta sea válida antes de utilizarla.

No basta con que la petición haya terminado: una respuesta HTTP como
`404` o `500` también debe tratarse como error.

### JavaScript

Administra el estado de la interfaz.

Debe decidir:

-   cuándo actualizar;
-   cuándo conservar el último dato;
-   cuándo mostrar un error;
-   cuándo indicar que los datos están antiguos;
-   qué respuesta es la más reciente.

### Chart.js

Es solamente la capa de visualización.

Chart.js puede actualizar los datos de un gráfico mediante su API
`update()`, pero no determina si esos datos son verdaderos ni de dónde
provienen.

------------------------------------------------------------------------

# 6. Formato de respuesta recomendado

En lugar de devolver solamente:

``` json
{
  "ventas": 1520
}
```

es preferible devolver metadatos que permitan conocer el estado de la
información:

``` json
{
  "ok": true,
  "data": {
    "ventas": 1520
  },
  "generated_at": "2026-09-11T13:15:00-04:00",
  "data_version": 1842
}
```

Esto permite que el frontend conozca:

-   si la operación fue correcta;
-   cuáles son los datos;
-   cuándo fueron generados;
-   qué versión representan.

El nombre exacto de los campos puede adaptarse al proyecto.

------------------------------------------------------------------------

# 7. Control de antigüedad

Una de las mejoras más importantes es que el frontend no piense
simplemente:

> "Tengo datos."

Debe pensar:

> "Tengo datos válidos obtenidos en determinado momento."

Por ejemplo:

``` text
Dato recibido:
13:15:00

Hora actual:
13:15:24

Antigüedad:
24 segundos
```

Entonces:

``` text
0 - 60 segundos
    -> ACTUALIZADO

60 - 180 segundos
    -> ANTIGUO / ADVERTENCIA

más de 180 segundos
    -> DATOS DESACTUALIZADOS
```

Los límites son solamente un ejemplo. Deben definirse según la
naturaleza de cada dato.

Una lista de productos puede tolerar varios minutos.

Un saldo financiero quizá requiera una política mucho más estricta.

------------------------------------------------------------------------

# 8. Actualización periódica

Un esquema sencillo sería:

``` text
Carga de página
      |
      v
Primera consulta
      |
      v
Guardar último estado válido
      |
      v
Esperar 30 segundos
      |
      v
Nueva consulta
      |
      +------ ÉXITO ------> reemplazar datos
      |
      +------ ERROR ------> conservar datos anteriores
      |
      v
Esperar otros 30 segundos
      |
      v
Repetir
```

El intervalo debe ser configurable.

Ejemplo:

``` javascript
const INTERVALO_ACTUALIZACION = 30000;
```

30 segundos.

No existe un intervalo universalmente correcto.

La frecuencia debe depender de:

-   importancia del dato;
-   frecuencia con que cambia;
-   cantidad de usuarios;
-   costo de la consulta;
-   capacidad del servidor;
-   necesidad operativa.

------------------------------------------------------------------------

# 9. Evitar respuestas fuera de orden

Existe un problema menos evidente.

Supongamos que se producen dos consultas:

``` text
Solicitud A -> datos antiguos
Solicitud B -> datos nuevos
```

Pero por las condiciones de la red:

``` text
B llega primero
A llega después
```

Si JavaScript acepta cualquier respuesta simplemente porque llegó, puede
terminar haciendo:

``` text
Datos nuevos
      ↓
Datos antiguos
```

Esto es incorrecto.

La solución es asociar las respuestas con información que permita
determinar cuál representa el estado más reciente.

Se pueden utilizar mecanismos como:

-   número de versión;
-   timestamp del servidor;
-   identificador de actualización;
-   control de solicitudes concurrentes;
-   cancelación de una solicitud anterior cuando corresponde.

Una política sencilla puede ser:

> **Nunca reemplazar el estado actual con una respuesta cuya versión sea
> anterior a la versión ya mostrada.**

------------------------------------------------------------------------

# 10. Control de errores

La actualización debe distinguir varios tipos de fallo.

### Error de red

``` text
No hay conexión
```

### Error HTTP

``` text
404
500
503
```

### Error de formato

El servidor debía devolver JSON, pero devuelve HTML o una respuesta
corrupta.

### Error de contenido

El JSON existe, pero faltan campos obligatorios.

Por ejemplo:

``` json
{
  "ok": true
}
```

cuando el frontend esperaba:

``` json
{
  "ok": true,
  "data": {...},
  "generated_at": "...",
  "data_version": 1842
}
```

Todos estos casos deberían impedir que la aplicación reemplace un dato
válido por una respuesta inválida.

------------------------------------------------------------------------

# 11. Patrón conceptual de JavaScript

Un pseudocódigo adecuado sería:

``` javascript
let ultimoEstadoValido = null;

async function actualizarDatos() {

    try {

        const response = await fetch('/api/ventas.php');

        if (!response.ok) {
            throw new Error('Error HTTP');
        }

        const respuesta = await response.json();

        if (!respuesta.ok) {
            throw new Error('Respuesta inválida');
        }

        validarRespuesta(respuesta);

        if (esMasNueva(respuesta, ultimoEstadoValido)) {

            ultimoEstadoValido = respuesta;

            actualizarInterfaz(respuesta);

            actualizarGrafico(respuesta);

            mostrarEstado('actualizado');
        }

    } catch (error) {

        // MUY IMPORTANTE:
        // NO modificar ultimoEstadoValido.

        mostrarEstado('error');

        registrarError(error);
    }
}
```

La idea importante no es copiar este código literalmente, sino respetar
la regla:

``` text
ÉXITO
  ↓
validar
  ↓
aceptar
  ↓
reemplazar estado
  ↓
actualizar gráfico

ERROR
  ↓
NO reemplazar estado
  ↓
conservar último dato válido
  ↓
informar al usuario
```

------------------------------------------------------------------------

# 12. Chart.js dentro de esta arquitectura

Chart.js solamente recibe datos que ya fueron considerados válidos.

Conceptualmente:

``` javascript
actualizarGrafico(datosValidos);
```

y no:

``` javascript
actualizarGrafico(cualquierRespuesta);
```

Cuando el dataset cambia, Chart.js permite actualizar el gráfico
mediante `chart.update()`. La biblioteca se ocupa de volver a
representar el gráfico, pero la decisión de qué datos son válidos debe
permanecer fuera de Chart.js.

Fuente: https://www.chartjs.org/docs/latest/developers/updates.html

------------------------------------------------------------------------

# 13. ¿Qué ocurre si se cae la web?

Supongamos:

``` text
13:00 -> datos válidos
13:00:30 -> datos válidos
13:01 -> servidor caído
13:01:30 -> servidor caído
13:02 -> servidor vuelve
```

El comportamiento recomendado es:

``` text
13:00
Ventas: 1.500
🟢 Actualizado

13:00:30
Ventas: 1.505
🟢 Actualizado

13:01
Ventas: 1.505
🔴 No se pudo actualizar
Último dato válido: 13:00:30

13:01:30
Ventas: 1.505
🔴 No se pudo actualizar
Último dato válido: 13:00:30

13:02
Ventas: 1.523
🟢 Actualizado
```

Esto es muy superior a:

``` text
13:01 -> Ventas: 0
```

porque `0` podría interpretarse como un dato real.

------------------------------------------------------------------------

# 14. Lo más importante: preservar la última verdad conocida

La interfaz debe tratar los datos como estados.

``` text
              ÚLTIMO ESTADO VÁLIDO
                       |
                       v
              ┌─────────────────┐
              │ Ventas = 1.505  │
              │ 13:00:30        │
              └────────┬────────┘
                       |
              nueva actualización
                       |
              ┌────────┴─────────┐
              |                  |
            ÉXITO              ERROR
              |                  |
              v                  v
        nuevo estado       conservar 1.505
              |                  |
              v                  v
        actualizar UI       mostrar advertencia
```

Esto constituye una de las reglas principales de confiabilidad del
frontend.

------------------------------------------------------------------------

# 15. ¿Por qué no simplemente borrar el gráfico?

Porque borrar el gráfico genera ambigüedad.

No sabemos si:

``` text
Sin datos
```

significa:

-   realmente hay cero datos;
-   la consulta falló;
-   el servidor está caído;
-   el usuario no tiene permisos;
-   ocurrió un error de JavaScript;
-   todavía no se hizo la primera consulta.

Es mejor representar explícitamente el estado.

------------------------------------------------------------------------

# 16. Separar "valor" de "estado"

Un componente debería manejar conceptualmente dos cosas:

``` text
VALOR
Ventas = 1.505

ESTADO
Última actualización correcta = 13:00:30
Estado de conexión = ERROR
```

No mezclar ambas cosas evita muchos errores.

El valor puede seguir siendo válido aunque el estado de actualización
sea:

``` text
ERROR
```

Esto significa:

> "Este valor fue correcto cuando se obtuvo, pero no hemos podido
> confirmar si sigue siendo el último."

------------------------------------------------------------------------

# 17. Estrategia recomendada para una aplicación empresarial

Para el stack:

``` text
PHP
+
MySQL/MariaDB
+
AJAX/Fetch
+
JavaScript
+
Chart.js
```

se recomienda:

### Backend

-   Base de datos como fuente de verdad.
-   Consultas bien definidas.
-   Transacciones cuando una operación afecte varios registros.
-   Validación de resultados.
-   Respuestas JSON consistentes.
-   Timestamp de generación.
-   Versión o identificador de datos cuando sea útil.

### Frontend

-   Actualización periódica configurable.
-   Validación de respuestas.
-   Control de errores HTTP.
-   Control de errores de red.
-   Control de formato JSON.
-   Conservación del último estado válido.
-   Detección de datos antiguos.
-   Protección contra respuestas fuera de orden.
-   Indicador visible del estado.
-   Registro de errores para diagnóstico.

### Visualización

-   Chart.js recibe solamente datos aceptados por el frontend.
-   El gráfico nunca determina la verdad del dato.
-   El gráfico no debe convertirse en almacenamiento de datos.
-   La interfaz debe indicar la antigüedad cuando sea relevante.

------------------------------------------------------------------------

# 18. Resultado de la arquitectura

El flujo completo queda:

``` text
                  ┌────────────────────┐
                  │    BASE DE DATOS   │
                  │                    │
                  │ FUENTE DE VERDAD   │
                  └─────────┬──────────┘
                            │
                            v
                  ┌────────────────────┐
                  │        PHP         │
                  │                    │
                  │ consulta           │
                  │ valida             │
                  │ autoriza           │
                  │ procesa            │
                  └─────────┬──────────┘
                            │
                            v
                    JSON + METADATOS
                            │
                            v
                  ┌────────────────────┐
                  │    AJAX / FETCH    │
                  │                    │
                  │ red / HTTP / JSON  │
                  └─────────┬──────────┘
                            │
                 ┌──────────┴──────────┐
                 │                     │
               ÉXITO                 ERROR
                 │                     │
                 v                     v
        validar respuesta       conservar último
                 │               dato válido
                 v                     │
        comprobar versión             │
                 │                     │
                 v                     v
       aceptar nuevo estado      mostrar advertencia
                 │                     │
                 └──────────┬──────────┘
                            │
                            v
                  ┌────────────────────┐
                  │    JAVASCRIPT      │
                  │                    │
                  │ estado + antigüedad│
                  └─────────┬──────────┘
                            │
                            v
                  ┌────────────────────┐
                  │      CHART.JS      │
                  │                    │
                  │    VISUALIZACIÓN   │
                  └────────────────────┘
```

------------------------------------------------------------------------

# 19. Principios fundamentales

## Principio 1

> **La base de datos es la fuente de verdad.**

## Principio 2

> **PHP es la autoridad que consulta y procesa los datos.**

## Principio 3

> **JavaScript nunca debe ser considerado una fuente de verdad.**

## Principio 4

> **Chart.js solamente visualiza datos que ya fueron considerados
> válidos.**

## Principio 5

> **Una actualización periódica puede ser suficiente y suele ser mucho
> más simple que tiempo real.**

## Principio 6

> **Una actualización fallida no debe destruir el último dato válido.**

## Principio 7

> **Un dato antiguo pero confirmado es diferente de un dato
> desconocido.**

## Principio 8

> **El usuario debe poder conocer la antigüedad y el estado de los datos
> cuando sea importante.**

## Principio 9

> **Las respuestas deben validarse antes de reemplazar el estado
> actual.**

## Principio 10

> **Una respuesta antigua nunca debería sobrescribir una respuesta más
> reciente.**

------------------------------------------------------------------------

# 20. Conclusión

Para una aplicación PHP + AJAX + JavaScript, no es necesario incorporar
Python ni utilizar tiempo real para conseguir una interfaz confiable.

Una estrategia de actualización periódica bien diseñada puede ofrecer
una excelente relación entre:

-   rendimiento;
-   simplicidad;
-   consumo de recursos;
-   mantenibilidad;
-   confiabilidad;
-   experiencia de usuario.

La característica más importante no es que el gráfico se actualice cada
5, 10 o 30 segundos.

La característica más importante es que el sistema pueda responder
correctamente a esta pregunta:

> **"¿Qué datos estoy mostrando y qué tan seguro estoy de que siguen
> siendo los últimos datos válidos?"**

Por eso, la arquitectura debe priorizar:

``` text
FUENTE DE VERDAD
       ↓
VALIDACIÓN
       ↓
TRANSPORTE
       ↓
CONTROL DE ESTADO
       ↓
CONSERVACIÓN DEL ÚLTIMO DATO VÁLIDO
       ↓
VISUALIZACIÓN
```

En esta arquitectura, Chart.js es intercambiable. Puede sustituirse
posteriormente por otra biblioteca sin modificar la lógica fundamental
de integridad.

La verdadera confiabilidad está en las capas anteriores al gráfico.

------------------------------------------------------------------------

## Referencias técnicas

-   Chart.js --- Updating Charts:
    https://www.chartjs.org/docs/latest/developers/updates.html

-   Chart.js --- API:
    https://www.chartjs.org/docs/latest/developers/api.html

-   MDN --- Using the Fetch API:
    https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch

-   MDN --- AbortController:
    https://developer.mozilla.org/docs/Web/API/AbortController
