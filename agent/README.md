# HMS Reception Print Agent

This small Node.js application runs on the reception PC, polls the HMS server for pending print jobs, and prints receipts to the local thermal printer.

## Setup

1. Install Node.js on the reception PC.
2. Copy `config.example.json` to `config.json` and fill in the values:
   - `apiBaseUrl`: URL of the HMS Laravel app.
   - `apiToken`: Value of the `PRINT_AGENT_TOKEN` from the server's `.env`.
   - `printer.interface`: `epson-port`, `usb`, `network`, or `serial`.
   - `printer.port`: Epson virtual port such as `ESDPRT001` or a COM port such as `COM8` (for `epson-port` interface).
   - `printer.name`: Windows printer name (for USB) or IP:port (for network).
3. Run `npm install` in this folder.
4. Start the agent: `npm start`.

## Running as a Windows Service

Use a tool like `pm2` or `nssm` to keep the agent running in the background and start it on boot.

### Example with PM2

```bash
npm install -g pm2
pm2 start index.js --name hms-print-agent
pm2 save
pm2 startup
```

## Printer Notes

The agent supports ESC/POS thermal printers via `node-thermal-printer` for formatting and raw byte output.

### Epson virtual ports (recommended for Windows)

For Epson printers that appear on a virtual port such as `ESDPRT001`, use the `epson-port` interface. The agent builds the receipt with `node-thermal-printer` and then writes the raw ESC/POS bytes directly to `\\.\ESDPRT001` (or `\\.\COMx` for a real serial port), matching the behaviour of `FilePrintConnector` in the HealthPro Laravel app.

Example:

```json
"printer": {
  "mode": "escpos",
  "interface": "epson-port",
  "port": "ESDPRT001"
}
```

### USB / Windows spooler

For USB or spooler-based printing, use `interface: "usb"` and set `name` to the exact Windows printer name.

### Network

Use `interface: "network"` and set `address` to `tcp://ip:port`.
