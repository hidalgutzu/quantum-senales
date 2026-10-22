# QUANTUM Señales

Señales simples de **COMPRAR / VENDER / ESPERAR** para BTC, ETH, SOL, XRP y ADA en 4H, 1D, 1 semana y 1 mes, con datos reales de Binance.

- Zona de entrada, stop y dos objetivos calculados con la volatilidad (ATR)
- **% de acierto histórico** de cada señal (cuántas veces llegó al objetivo antes que al stop)
- Cuánto meter como máximo según tu capital y riesgo
- Radar de las 5 monedas y alineación entre temporalidades
- Alertas a Telegram: en la página mientras está abierta, y 24/7 con `quantum-alertas.php`

## Uso
Abre `index.html` en cualquier hosting (o con GitHub Pages). No necesita servidor ni API keys.

## Alertas 24/7 por Telegram
1. Crea un bot con **@BotFather** y obtén tu chat ID con **@userinfobot**.
2. Edita el inicio de `quantum-alertas.php`: token, chat ID y `$SECRET`.
3. Súbelo a tu hosting con PHP y prueba: `quantum-alertas.php?key=TU_CLAVE&test=1`
4. Cron cada 15 minutos: `php /ruta/a/quantum-alertas.php`

Nunca subas tu token real a un repo público.

## Aviso
Herramienta de análisis técnico con fines educativos. El acierto histórico no garantiza resultados futuros. No es asesoría financiera.

Creado por Dédalo Multimedia.
