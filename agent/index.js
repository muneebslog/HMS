const axios = require('axios');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const { ThermalPrinter, PrinterTypes } = require('node-thermal-printer');

function toWindowsDevicePath(port) {
  if (/^(COM|ESDPRT)\d+$/i.test(port)) {
    return `\\\\.\\${port}`;
  }
  return port;
}

function setLeftMargin(printer, columns) {
  // ESC/POS GS L nL nH: set left margin in dots.
  // Approximate 12 dots per Font-A character column.
  const dots = Math.max(0, Math.round((columns || 0) * 12));
  const nL = dots & 0xff;
  const nH = (dots >> 8) & 0xff;
  printer.append(Buffer.from([0x1d, 0x4c, nL, nH]));
}

function writeRawToPort(buffer, port) {
  const devicePath = toWindowsDevicePath(port);

  return new Promise((resolve, reject) => {
    const stream = fs.createWriteStream(devicePath, { autoClose: true });
    let finished = false;

    function cleanup(error) {
      if (finished) return;
      finished = true;
      stream.destroy();
      if (error) {
        reject(error);
      } else {
        resolve();
      }
    }

    stream.on('error', cleanup);
    stream.on('finish', cleanup);

    stream.write(buffer, (error) => {
      if (error) {
        cleanup(error);
        return;
      }
      stream.end();
    });
  });
}

function sendRawToWindowsSpooler(buffer, printerName) {
  const tmpFile = path.join(os.tmpdir(), `hms-print-${crypto.randomBytes(8).toString('hex')}.bin`);
  fs.writeFileSync(tmpFile, buffer);

  const psScriptPath = path.join(__dirname, 'print-raw.ps1');

  return new Promise((resolve, reject) => {
    const child = spawn('powershell.exe', [
      '-NoProfile',
      '-ExecutionPolicy', 'Bypass',
      '-File', psScriptPath,
      '-PrinterName', printerName,
      '-FilePath', tmpFile,
    ], { windowsHide: true });

    let stderr = '';
    child.stderr.on('data', (data) => { stderr += data.toString(); });

    child.on('error', (error) => {
      try { fs.unlinkSync(tmpFile); } catch (e) { /* ignore */ }
      reject(error);
    });

    child.on('close', (code) => {
      try { fs.unlinkSync(tmpFile); } catch (e) { /* ignore */ }

      if (code !== 0) {
        reject(new Error(`Windows spooler print failed (exit ${code}): ${stderr.trim() || 'unknown error'}`));
      } else {
        resolve();
      }
    });
  });
}

const configPath = process.argv[2] || path.join(__dirname, 'config.json');

if (!fs.existsSync(configPath)) {
  console.error(`Config file not found: ${configPath}`);
  console.error('Copy config.example.json to config.json and update the values.');
  process.exit(1);
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

const api = axios.create({
  baseURL: config.apiBaseUrl.replace(/\/$/, ''),
  headers: {
    Authorization: `Bearer ${config.apiToken}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 30000,
});

function buildPrinter() {
  const printerConfig = config.printer || {};

  if (printerConfig.mode !== 'escpos') {
    throw new Error(`Unsupported printer mode: ${printerConfig.mode}`);
  }

  const interfaceType = printerConfig.interface || 'epson-port';

  let printerType;
  let interfaceValue;

  switch (interfaceType) {
    case 'epson-port':
      printerType = PrinterTypes.EPSON;
      // node-thermal-printer still needs an interface value for internal setup,
      // but we will send the raw bytes ourselves via the virtual port.
      interfaceValue = toWindowsDevicePath(printerConfig.port || 'ESDPRT001');
      break;
    case 'windows-spooler':
      printerType = PrinterTypes.EPSON;
      // We build the ESC/POS buffer with node-thermal-printer and then send it
      // as a RAW job through the Windows print spooler using the printer name.
      // Pass a dummy interface so node-thermal-printer doesn't try to load a
      // third-party printer driver; we handle the actual spooler write ourselves.
      interfaceValue = {
        execute: () => Promise.resolve(),
        isPrinterConnected: () => Promise.resolve(true),
      };
      break;
    case 'usb':
      printerType = PrinterTypes.EPSON;
      interfaceValue = `printer:${printerConfig.name}`;
      break;
    case 'network':
      printerType = PrinterTypes.EPSON;
      interfaceValue = printerConfig.address;
      break;
    case 'serial':
      printerType = PrinterTypes.EPSON;
      interfaceValue = printerConfig.port;
      break;
    default:
      throw new Error(`Unsupported printer interface: ${interfaceType}`);
  }

  return new ThermalPrinter({
    type: printerType,
    interface: interfaceValue,
    characterSet: printerConfig.characterSet || 'SLOVENIA',
    removeSpecialCharacters: printerConfig.removeSpecialCharacters || false,
    lineCharacter: printerConfig.lineCharacter || '-',
    width: printerConfig.width || 48,
  });
}

async function printReceipt(printer, job) {
  const invoice = job.invoice;

  // Shift the whole receipt to the right so the printer does not clip
  // the leftmost characters.
  setLeftMargin(printer, config.printer?.leftPadding);

  printer.alignCenter();
  printer.bold(true);
  printer.setTextSize(1, 1);
  printer.println(config.headerText || 'HMS');
  printer.setTextNormal();
  printer.bold(false);
  printer.println(config.subHeaderText || 'Invoice Receipt');

  const copyFor = invoice.copy_for;
  if (copyFor) {
    printer.newLine();
    printer.alignCenter();
    printer.bold(true);
    printer.setTextSize(1, 1);
    printer.println(`${copyFor.toUpperCase()} COPY`);
    printer.setTextNormal();
    printer.bold(false);
  }

  printer.drawLine();

  printer.alignLeft();
  printer.println(`Invoice #: ${invoice.invoice_number}`);
  printer.println(`Date: ${invoice.created_at}`);
  printer.println('Patient:');
  printer.bold(true);
  printer.setTextSize(1, 1);
  printer.println(invoice.patient.name);
  printer.setTextNormal();
  printer.bold(false);
  printer.newLine();

  const tokenItem = invoice.items.find((item) => item.token_number);
  if (tokenItem) {
    printer.alignCenter();
    printer.bold(true);
    printer.setTextSize(1, 1);
    printer.println(`TOKEN: ${tokenItem.token_number}`);
    printer.setTextNormal();
    printer.bold(false);
    printer.newLine();
  }

  const isLabInvoice = job.payload?.type === 'lab_invoice';

  printer.alignLeft();
  invoice.items.forEach((item) => {
    if (isLabInvoice) {
      printer.bold(true);
      printer.println(item.service_name);
      printer.bold(false);

      const details = [];
      if (item.test_code) {
        details.push(`Code: ${item.test_code}`);
      }
      if (item.time_required) {
        const displayTime = item.time_required.toLowerCase() === 'same day'
          ? 'Next day'
          : item.time_required;
        details.push(`Time: ${displayTime}`);
      }
      if (details.length > 0) {
        printer.println(`  ${details.join(' | ')}`);
      }

      const priceLabel = '  Price:';
      const priceValue = item.price.toFixed(2);
      const pricePadding = Math.max(0, printer.getWidth() - priceLabel.length - priceValue.length);
      printer.println(`${priceLabel}${' '.repeat(pricePadding)}${priceValue}`);
    } else {
      const name = item.service_name.padEnd(24, ' ').substring(0, 24);
      const price = item.price.toFixed(2).padStart(8, ' ');
      printer.println(`${name}${price}`);

      if (item.doctor_name) {
        printer.println(`  Dr. ${item.doctor_name}`);
      }
    }
  });

  printer.drawLine();
  printer.bold(true);
  const totalLabel = 'TOTAL';
  const totalValue = invoice.total.toFixed(2);
  const padding = Math.max(0, printer.getWidth() - totalLabel.length - totalValue.length);
  printer.println(`${totalLabel}${' '.repeat(padding)}${totalValue}`);
  printer.bold(false);

  if (invoice.qr_url) {
    printer.newLine();
    printer.alignCenter();
    printer.println('Scan for invoice details');
    printer.printQR(invoice.qr_url, { cellSize: 5, correction: 'M' });
    printer.setTextNormal();
    printer.alignCenter();
    printer.println(invoice.qr_url);
  }

  printer.newLine();
  printer.alignCenter();
  printer.println(config.footerText || 'Thank you!');
  printer.newLine();
  printer.newLine();
  printer.cut();

  if (config.printer?.interface === 'epson-port') {
    const buffer = printer.getBuffer();
    await writeRawToPort(buffer, config.printer.port);
    printer.clear();
  } else if (config.printer?.interface === 'windows-spooler') {
    const buffer = printer.getBuffer();
    await sendRawToWindowsSpooler(buffer, config.printer.name);
    printer.clear();
  } else {
    const result = await printer.execute();

    if (!result) {
      throw new Error('Printer returned false.');
    }
  }
}

async function markPrinted(job) {
  await api.post(`/api/print-jobs/${job.id}/printed`);
  console.log(`[${new Date().toISOString()}] Job #${job.id} marked as printed.`);
}

async function markFailed(job, error) {
  const message = error instanceof Error ? error.message : String(error);
  await api.post(`/api/print-jobs/${job.id}/failed`, { error_message: message });
  console.error(`[${new Date().toISOString()}] Job #${job.id} failed: ${message}`);
}

async function poll() {
  try {
    const response = await api.get('/api/print-jobs/pending');
    const jobs = response.data.data || [];

    if (jobs.length > 0) {
      console.log(`[${new Date().toISOString()}] Found ${jobs.length} pending job(s).`);
    }

    const printer = buildPrinter();

    // Epson virtual ports (\\.\ESDPRT001) and the Windows spooler are not
    // reliably probe-able from Node. Skip the connectivity check for those
    // interfaces and let the actual print attempt surface real errors.
    if (!['epson-port', 'windows-spooler'].includes(config.printer?.interface)) {
      const isConnected = await printer.isPrinterConnected();

      if (!isConnected) {
        console.error(`[${new Date().toISOString()}] Printer not connected.`);
        return;
      }
    }

    for (const job of jobs) {
      try {
        await printReceipt(printer, job);
        await markPrinted(job);
      } catch (error) {
        await markFailed(job, error);
      }
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`[${new Date().toISOString()}] Poll error: ${message}`);
  }
}

function start() {
  console.log(`[${new Date().toISOString()}] HMS Reception Agent started.`);
  console.log(`[${new Date().toISOString()}] API: ${config.apiBaseUrl}`);
  console.log(`[${new Date().toISOString()}] Printer mode: ${config.printer?.mode}`);

  if (config.printer?.interface === 'epson-port') {
    console.log(`[${new Date().toISOString()}] Printer port: ${toWindowsDevicePath(config.printer.port)}`);
  }

  if (config.printer?.interface === 'windows-spooler') {
    console.log(`[${new Date().toISOString()}] Windows spooler printer: ${config.printer.name}`);
  }

  poll();
  setInterval(poll, config.pollIntervalMs || 2000);
}

start();
