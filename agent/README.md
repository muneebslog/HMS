# HMS Reception Print Agent

This small Node.js application runs on the reception PC, polls the HMS server for pending print jobs, and prints receipts to the local thermal printer.

## Setup

1. Install Node.js on the reception PC.
2. Copy `config.example.json` to `config.json` and fill in the values:
   - `apiBaseUrl`: URL of the HMS Laravel app.
   - `apiToken`: Value of the `PRINT_AGENT_TOKEN` from the server's `.env`.
   - `printer.interface`: `usb`, `network`, or `serial`.
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

The agent currently supports ESC/POS thermal printers via `node-thermal-printer`. Make sure the printer driver is installed and the printer name matches exactly (for USB mode) or the IP/port is correct (for network mode).
