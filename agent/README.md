# HMS Reception Print Agent

This small Node.js application runs on the reception PC, polls the HMS server for pending print jobs, and prints receipts to the local thermal printer.

## Setup

1. Install Node.js on the reception PC.
2. Copy `config.example.json` to `config.json` and fill in the values:
   - `apiBaseUrl`: URL of the HMS Laravel app.
   - `apiToken`: Value of the `PRINT_AGENT_TOKEN` from the server's `.env`.
   - `printer.interface`: `windows-spooler`, `epson-port`, `usb`, `network`, or `serial`.
   - `printer.name`: Windows printer name (required for `windows-spooler` and `usb`).
   - `printer.port`: Epson virtual port such as `ESDPRT001` or a COM port such as `COM8` (for `epson-port` or `serial` interface).
   - `printer.address`: IP address such as `tcp://192.168.1.50:9100` (for `network` interface).
3. Run `npm install` in this folder.
4. Start the agent: `npm start`.

## Running as a Windows Service

The agent can be installed as a Windows service so it starts automatically on boot, runs in the background without a logged-in user, and restarts automatically if it crashes.

### Install the service

Open PowerShell or Command Prompt **as Administrator** in this folder and run:

```bash
npm run service:install
```

This installs a Windows service named **HMS Reception Print Agent** and starts it immediately.

### Check the service

Open the Services manager (`services.msc`) and look for **HMS Reception Print Agent**. It should be set to:

- **Startup type:** Automatic
- **Status:** Running

You can also check the log files created by the service in the `daemon` folder inside this project directory.

### Uninstall the service

To remove the service, run as Administrator:

```bash
npm run service:uninstall
```

### Alternative: PM2

If you prefer PM2 instead of a native Windows service:

```bash
npm install -g pm2
pm2 start index.js --name hms-print-agent
pm2 save
pm2 startup
```

## Printer Notes

The agent supports ESC/POS thermal printers via `node-thermal-printer` for formatting and raw byte output.

### Windows spooler / Epson virtual ports (recommended)

Epson USB receipt printers using the Epson Advanced Printer Driver show up on virtual ports like `ESDPRT001`. Those ports are **not** raw file devices, so opening `\\.\ESDPRT001` directly fails. Instead, use the `windows-spooler` interface: the agent builds the ESC/POS buffer and submits it as a RAW job to the Windows print spooler by printer name.

Example:

```json
"printer": {
  "mode": "escpos",
  "interface": "windows-spooler",
  "name": "EPSON TM-T90 Receipt"
}
```

### Direct raw port (`epson-port`)

If your setup exposes a real serial/COM port or a raw Epson virtual port that can be opened as a file (e.g. with the Generic/Text Only driver), use `interface: "epson-port"`. The agent writes raw bytes to `\\.\ESDPRT001` or `\\.\COMx`.

```json
"printer": {
  "mode": "escpos",
  "interface": "epson-port",
  "port": "ESDPRT001"
}
```

### USB via node-thermal-printer driver

For USB or spooler-based printing with a third-party raw driver, use `interface: "usb"` and set `name` to the exact Windows printer name. This mode requires an additional package such as the `printer` npm package.

### Network

Use `interface: "network"` and set `address` to `tcp://ip:port`.
