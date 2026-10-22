# QUANTUM Signals

**Clear BUY / SELL / WAIT crypto signals with entry, stop, targets, position size and Telegram alerts — no charts to decode.**

![QUANTUM Signals](screenshot.png)

**[▶ Live demo](https://hidalgutzu.github.io/quantum-senales/)** · [Español](#español)

## What it does

- **BUY / SELL / WAIT** for BTC, ETH, SOL, XRP and ADA on 4H, 1D, 1W and 1M
- **Real historical hit rate** of each signal: how often it reached target 1 before the stop
- **Trade plan**: entry zone, stop and two targets based on real volatility (ATR)
- **Max position size** for your capital and risk %, with leverage if needed
- **Radar** ranking the 5 coins by opportunity, plus timeframe alignment
- **Plain-language "why"** behind every signal
- **Honest filter**: if a signal historically fails more than it wins, it says WAIT
- **Telegram alerts** while the page is open, or **24/7** with the included PHP bot
- Closed candles only, so signals never repaint mid-candle

Live data from Binance. No server, no API keys, one HTML file.

## Quick start

Open `index.html` in any browser or host it anywhere (GitHub Pages works).

## 24/7 Telegram alerts

1. Create a bot with **@BotFather** and get your chat ID from **@userinfobot**.
2. Edit the top of `quantum-alertas.php`: token, chat ID and `$SECRET`.
3. Upload it to any PHP hosting and test: `quantum-alertas.php?key=YOUR_KEY&test=1`
4. Add a cron job every 15 minutes: `php /path/to/quantum-alertas.php > /dev/null 2>&1`

It only messages you on **strong** signals: score ≥ 60, hit rate ≥ 55%, and the higher timeframe not against it.

> Never commit your real bot token to a public repo.

## Disclaimer

Technical analysis tool for educational purposes. Past hit rates do not guarantee future results. Not financial advice. Always use the stop.

---

## Español

**Señales cripto claras de COMPRAR / VENDER / ESPERAR con entrada, stop, objetivos, tamaño de posición y alertas a Telegram, sin gráficas que descifrar.**

- **COMPRAR / VENDER / ESPERAR** para BTC, ETH, SOL, XRP y ADA en 4H, 1D, 1S y 1M
- **% de acierto histórico real** de cada señal
- **Plan**: zona de entrada, stop y dos objetivos calculados con la volatilidad (ATR)
- **Cuánto meter como máximo** según tu capital y riesgo
- **Radar** de las 5 monedas y alineación entre temporalidades
- **"Por qué"** de cada señal en lenguaje simple
- **Filtro honesto**: si una señal históricamente falla más de lo que acierta, dice ESPERAR
- **Alertas a Telegram** con la página abierta o **24/7** con el robot PHP incluido

### Alertas 24/7
1. Crea un bot con **@BotFather** y obtén tu chat ID con **@userinfobot**.
2. Edita el inicio de `quantum-alertas.php`: token, chat ID y `$SECRET`.
3. Súbelo a tu hosting y prueba: `quantum-alertas.php?key=TU_CLAVE&test=1`
4. Cron cada 15 minutos: `php /ruta/a/quantum-alertas.php > /dev/null 2>&1`

Herramienta educativa de análisis técnico. No es asesoría financiera.

---

Made by [Dédalo Multimedia](https://dedalomultimedia.com) · If this helped you, leave a ⭐
