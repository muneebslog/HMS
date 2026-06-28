# HMS Thermal Printing Guide

This guide explains how receipt printing works in the HMS application, how to set it up, and how to troubleshoot common problems.

---

## Table of Contents

1. [How It Works](#how-it-works)
2. [What You Need](#what-you-need)
3. [Server-Side Setup](#server-side-setup)
4. [Reception PC Setup](#reception-pc-setup)
5. [Printer Configuration](#printer-configuration)
6. [Running the Agent on Startup](#running-the-agent-on-startup)
7. [Monitoring Print Jobs](#monitoring-print-jobs)
8. [Common Errors & Fixes](#common-errors--fixes)
9. [Adding or Changing Printers](#adding-or-changing-printers)
10. [Developer Reference](#developer-reference)

---

## How It Works

The HMS app runs on a server, but the thermal printer is physically connected to the reception PC. To bridge this gap, we use a **server-side print queue** plus a **local reception agent**.

```
User clicks "Print" on any device
        │
        ▼
Laravel creates a PrintJob record (status = pending)
        │
        ▼
Reception agent polls /api/print-jobs/pending
        │
        ▼
Agent prints the receipt on the local thermal printer
        │
        ▼
Agent reports status back (printed / failed)
```

### Key points

- Users can trigger prints from mobile, tablet, or PC browsers.
- The actual printing always happens on the reception PC where the thermal printer is installed.
- The browser print dialog is **not shown** on the reception PC; the agent prints silently.
- If the reception PC is offline, jobs stay `pending` and print once it comes back online.
- The existing `invoices.print` view is kept as a manual preview/fallback but is no longer used by default.

---

## What You Need

### Server

- HMS Laravel application deployed and accessible over HTTPS (recommended for production).
- `PRINT_AGENT_TOKEN` set in the server `.env` file.

### Reception PC

- Windows PC (this guide assumes Windows; the agent can run on macOS/Linux with minor adjustments).
- Thermal receipt printer installed and working.
- Node.js 18+ installed.
- Network access to the HMS server.

---

## Server-Side Setup

1. **Set the agent token**

   Edit the server `.env` file and add a secure random token:

   ```env
   PRINT_AGENT_TOKEN=your-very-long-random-token-here
   ```

   Keep this token secret. It is the only thing the reception agent uses to authenticate with the API.

2. **Verify the API routes**

   After deployment, the following routes should be reachable:

   ```
   GET    /api/print-jobs/pending
   POST   /api/print-jobs/{id}/printed
   POST   /api/print-jobs/{id}/failed
   ```

   You can confirm them with:

   ```bash
   php artisan route:list --path=api
   ```

3. **Monitor print jobs in the app**

   Log in as a receptionist or management user and visit:

   ```
   /reception/print-jobs
   ```

   Here you can see pending, printed, and failed jobs, and retry failed ones.

---

## Reception PC Setup

### 1. Install Node.js

Download and install Node.js LTS from [https://nodejs.org/](https://nodejs.org/).

Verify installation in Command Prompt or PowerShell:

```bash
node -v
npm -v
```

### 2. Locate the agent

The agent is inside the HMS project at:

```
C:\Projects\HMS\agent\
```

Files:

| File | Purpose |
|------|---------|
| `index.js` | Main agent application |
| `package.json` | Node dependencies |
| `config.example.json` | Example configuration |
| `README.md` | Short reference |

### 3. Create the configuration file

Copy the example config:

```bash
cd C:\Projects\HMS\agent
copy config.example.json config.json
```

Edit `config.json`:

```json
{
  "apiBaseUrl": "https://hms.yourdomain.com",
  "apiToken": "your-PRINT_AGENT_TOKEN-from-server-env",
  "pollIntervalMs": 2000,
  "headerText": "Your Clinic Name",
  "subHeaderText": "Invoice Receipt",
  "footerText": "Thank you for visiting!",
  "printer": {
    "mode": "escpos",
    "interface": "usb",
    "name": "EPSON TM-T82 Receipt",
    "width": 48,
    "characterSet": "SLOVENIA",
    "removeSpecialCharacters": false,
    "lineCharacter": "-"
  }
}
```

### 4. Install dependencies

```bash
cd C:\Projects\HMS\agent
npm install
```

### 5. Test the agent

```bash
npm start
```

You should see output like:

```
[2026-06-24T19:00:00.000Z] HMS Reception Agent started.
[2026-06-24T19:00:00.000Z] API: https://hms.yourdomain.com
[2026-06:00:00.000Z] Printer mode: escpos
```

Create a test invoice from the HMS web app. The agent should pick up the pending job and print it.

---

## Printer Configuration

The agent currently supports **ESC/POS** thermal printers through `node-thermal-printer`.

### Finding your printer name on Windows

1. Open **Settings → Bluetooth & devices → Printers & scanners**.
2. Click your thermal printer.
3. The name at the top is what you need (e.g., `EPSON TM-T82 Receipt`).
4. Copy it exactly into `config.json` under `printer.name`.

### Supported interfaces

| Interface | Config value | Notes |
|-----------|--------------|-------|
| USB | `"usb"` | Uses the Windows printer name |
| Network | `"network"` | Use `address` instead of `name`, e.g., `"192.168.1.100:9100"` |
| Serial | `"serial"` | Use `port` instead of `name`, e.g., `"COM3"` |

### Common ESC/POS printer brands

The agent defaults to Epson ESC/POS commands, which work with most thermal receipt printers including:

- Epson TM-series
- Bixolon
- Star (partial)
- Xprinter
- Goojprt

If your printer does not understand ESC/POS, you may need to adjust the printer type in `index.js` or use a different printing strategy.

### Paper width

- `width: 48` for 80mm paper
- `width: 32` for 58mm paper

Adjust based on your printer.

---

## Running the Agent on Startup

The agent must keep running in the background. Use one of these methods.

### Option A: PM2 (Recommended)

PM2 is a Node.js process manager that can auto-start on boot.

1. Install PM2 globally:

   ```bash
   npm install -g pm2
   ```

2. Start the agent with PM2:

   ```bash
   cd C:\Projects\HMS\agent
   pm2 start index.js --name hms-print-agent
   pm2 save
   pm2 startup
   ```

3. PM2 will print a command to run as Administrator to enable auto-start. Run that command.

4. Check status anytime:

   ```bash
   pm2 status
   pm2 logs hms-print-agent
   ```

### Option B: Windows Task Scheduler

1. Open **Task Scheduler**.
2. Click **Create Task**.
3. On the **General** tab:
   - Name: `HMS Print Agent`
   - Select **Run whether user is logged on or not**.
   - Check **Run with highest privileges**.
4. On the **Triggers** tab, click **New**:
   - Begin the task: **At startup**
   - Check **Delay task for** and set `30 seconds`.
5. On the **Actions** tab, click **New**:
   - Action: **Start a program**
   - Program: `C:\Program Files\nodejs\node.exe`
   - Add arguments: `C:\Projects\HMS\agent\index.js`
   - Start in: `C:\Projects\HMS\agent`
6. On the **Conditions** tab, uncheck **Start the task only if the computer is on AC power**.
7. Click **OK** and enter the Windows user password when prompted.

### Option C: Manual during work hours

For testing only, run:

```bash
cd C:\Projects\HMS\agent
npm start
```

Keep the Command Prompt window open.

---

## Monitoring Print Jobs

1. Open HMS in a browser.
2. Go to **Reception → Print Jobs** (`/reception/print-jobs`).
3. You can see:
   - **Pending** jobs waiting for the agent
   - **Printed** jobs that succeeded
   - **Failed** jobs with error messages
4. Click the retry icon on failed jobs to send them back to pending.

---

## Common Errors & Fixes

### "Unauthorized" or 401 in agent logs

**Cause:** The `apiToken` in `config.json` does not match `PRINT_AGENT_TOKEN` in the server `.env`.

**Fix:**

1. Check the server `.env`:

   ```env
   PRINT_AGENT_TOKEN=your-token
   ```

2. Check `agent/config.json`:

   ```json
   "apiToken": "your-token"
   ```

3. Make sure both match exactly (no extra spaces).

### "Printer not connected"

**Cause:** The printer name in `config.json` does not match the Windows printer name, or the printer is offline.

**Fix:**

1. Open Windows printer settings and copy the exact printer name.
2. Update `printer.name` in `config.json`.
3. Make sure the printer is powered on and shows **Ready** in Windows.
4. Print a Windows test page first to confirm the driver works.

### Prints are blank or garbled

**Cause:** The printer does not understand ESC/POS, or the character set/width is wrong.

**Fix:**

1. Confirm your printer supports ESC/POS. Check the manual or test with Epson ESC/POS commands.
2. Try changing `characterSet` to one supported by your printer (e.g., `PC437_USA`, `WPC1252`).
3. Adjust `width` to match your paper (48 for 80mm, 32 for 58mm).

### Agent picks up jobs but nothing prints

**Cause:** The agent cannot communicate with the printer at the low level.

**Fix:**

1. For USB on Windows, make sure you installed the manufacturer's driver (not just the generic Microsoft driver).
2. Try running Command Prompt as Administrator when starting the agent.
3. Check Windows Event Viewer for printer errors.

### Pending jobs are not picked up

**Cause:** The agent is not running, cannot reach the server, or the jobs are not pending.

**Fix:**

1. Check the agent is running (`pm2 status` or Task Manager).
2. Check the agent logs for errors.
3. Visit `/reception/print-jobs` and confirm jobs are `pending`.
4. From the reception PC, open the HMS URL in a browser to confirm network access.

### "Poll error: connect ECONNREFUSED"

**Cause:** The server URL is wrong, the server is down, or a firewall is blocking the connection.

**Fix:**

1. Double-check `apiBaseUrl` in `config.json`.
2. Make sure the URL is reachable from the reception PC browser.
3. Check server firewall rules for ports 80/443.

### Duplicate prints

**Cause:** The agent processed a job but failed to report `printed` back to the server.

**Fix:**

1. Check network stability between reception PC and server.
2. Check server logs for the `/api/print-jobs/{id}/printed` endpoint.
3. Jobs left pending will print again on the next poll; mark them manually from the monitoring page if needed.

---

## Adding or Changing Printers

1. Install the new printer on the reception PC and confirm it prints a Windows test page.
2. Update `agent/config.json`:

   ```json
   "printer": {
     "interface": "usb",
     "name": "NEW PRINTER NAME"
   }
   ```

3. Restart the agent:

   ```bash
   pm2 restart hms-print-agent
   ```

   Or restart the Windows scheduled task.

4. Create a test invoice and verify printing.

### Multiple reception PCs

If you have multiple reception PCs with their own printers:

1. Each PC gets its own agent installation.
2. Each agent uses the same `PRINT_AGENT_TOKEN`.
3. All agents pull from the same pending queue. Whichever agent polls first will print the job.

If you need each PC to print only certain invoices, you will need to extend the system (e.g., assign printers to shifts or users).

---

## Developer Reference

### Relevant files

| File | Purpose |
|------|---------|
| `app/Models/PrintJob.php` | Print job model |
| `app/Enums/PrintJobStatus.php` | Status enum |
| `app/Actions/CreatePrintJob.php` | Creates print jobs from invoices |
| `app/Http/Controllers/Api/PrintJobController.php` | API for the agent |
| `app/Http/Middleware/EnsurePrintAgentToken.php` | Agent API authentication |
| `routes/api.php` | API routes |
| `config/services.php` | `print_agent.token` config |
| `resources/views/pages/reception/⚡print-jobs.blade.php` | Monitoring page |
| `resources/views/pages/reception/⚡reservation.blade.php` | Queues print on arrival |
| `resources/views/pages/reception/⚡walkin.blade.php` | Queues print on save |
| `resources/views/pages/reception/⚡lab-entry.blade.php` | Queues print on save |
| `resources/views/pages/reception/⚡invoices.blade.php` | Re-print buttons |
| `agent/index.js` | Reception agent |
| `agent/config.example.json` | Agent config template |

### Creating a print job manually

```php
use App\Actions\CreatePrintJob;
use App\Models\Invoice;

$invoice = Invoice::first();
app(CreatePrintJob::class)->create($invoice);
```

### API authentication

All agent endpoints require the header:

```
Authorization: Bearer <PRINT_AGENT_TOKEN>
```

---

## Quick Checklist

- [ ] `PRINT_AGENT_TOKEN` set in server `.env`
- [ ] Server deployed and `/api/print-jobs/pending` reachable
- [ ] Node.js installed on reception PC
- [ ] `agent/config.json` created from `config.example.json`
- [ ] Printer name copied exactly from Windows
- [ ] `npm install` ran in `agent/` folder
- [ ] `npm start` or PM2 started successfully
- [ ] Agent runs on startup (PM2 or Task Scheduler)
- [ ] Test invoice printed successfully
- [ ] Monitoring page shows the job as `printed`
