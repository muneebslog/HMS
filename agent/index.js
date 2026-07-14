const axios = require('axios');
const fs = require('fs');
const path = require('path');
const { ThermalPrinter, PrinterTypes } = require('node-thermal-printer');

function toWindowsDevicePath(port) {
  if (/^(COM|ESDPRT)\d+$/i.test(port)) {
    return `\\\\.\\${port}`;
  }
  return port;
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
    case 'usb':
      printerType = PrinterTypes.EPSON;
      interfaceValue = printerConfig.name;
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

  printer.alignCenter();
  printer.bold(true);
  printer.setTextSize(1, 1);
  printer.println(config.headerText || 'HMS');
  printer.setTextNormal();
  printer.bold(false);
  printer.println(config.subHeaderText || 'Invoice Receipt');
  printer.drawLine();

  printer.alignLeft();
  printer.println(`Invoice #: ${invoice.invoice_number}`);
  printer.println(`Date: ${invoice.created_at}`);
  printer.println(`Patient: ${invoice.patient.name}`);
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

  printer.alignLeft();
  invoice.items.forEach((item) => {
    const name = item.service_name.padEnd(24, ' ').substring(0, 24);
    const price = item.price.toFixed(2).padStart(8, ' ');
    printer.println(`${name}${price}`);

    if (item.doctor_name) {
      printer.println(`  Dr. ${item.doctor_name}`);
    }
  });

  printer.drawLine();
  printer.bold(true);
  const totalLabel = 'TOTAL';
  const totalValue = invoice.total.toFixed(2);
  const padding = Math.max(0, printer.getWidth() - totalLabel.length - totalValue.length);
  printer.println(`${totalLabel}${' '.repeat(padding)}${totalValue}`);
  printer.bold(false);

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
  } else {
    const result = await printer.execute();

    if (!result) {
      throw new Error('Printer returned false.');
    }
  }
}

async function printLabReceipt(printer, job) {
  const invoice = job.invoice;
  const copyFor = job.payload?.copy_for || 'patient';
  const isPatientCopy = copyFor === 'patient';

  printer.alignCenter();
  printer.bold(true);
  printer.setTextSize(1, 1);
  printer.println(config.headerText || 'HMS');
  printer.setTextNormal();
  printer.bold(false);
  printer.println(isPatientCopy ? 'PATIENT COPY' : 'LAB COPY');
  printer.println(config.subHeaderText || 'Lab Receipt');
  printer.drawLine();

  printer.alignLeft();
  printer.println(`Invoice #: ${invoice.invoice_number}`);
  printer.println(`Date: ${invoice.created_at}`);
  printer.println(`Patient: ${invoice.patient.name}`);
  printer.newLine();

  printer.alignLeft();
  invoice.items.forEach((item) => {
    const name = item.service_name.padEnd(24, ' ').substring(0, 24);
    const price = item.price.toFixed(2).padStart(8, ' ');
    printer.println(`${name}${price}`);
    printer.println(`  Code: ${item.test_code || '-'}`);

    if (item.time_required) {
      printer.println(`  Time: ${item.time_required}`);
    }
  });

  printer.drawLine();
  printer.bold(true);
  const totalLabel = 'TOTAL';
  const totalValue = invoice.total.toFixed(2);
  const padding = Math.max(0, printer.getWidth() - totalLabel.length - totalValue.length);
  printer.println(`${totalLabel}${' '.repeat(padding)}${totalValue}`);
  printer.bold(false);

  if (isPatientCopy && invoice.qr_url) {
    printer.newLine();
    printer.alignCenter();
    printer.println('Scan for report:');
    printer.printQR(invoice.qr_url, { cellSize: 4, correction: 'M', model: 2 });
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

    // Epson virtual ports (\\.\ESDPRT001) are raw Windows device paths.
    // fs.existsSync is unreliable for them, so skip the connectivity probe
    // and let the actual write attempt surface any errors.
    if (config.printer?.interface !== 'epson-port') {
      const isConnected = await printer.isPrinterConnected();

      if (!isConnected) {
        console.error(`[${new Date().toISOString()}] Printer not connected.`);
        return;
      }
    }

    for (const job of jobs) {
      try {
        if (job.payload?.type === 'lab_invoice') {
          await printLabReceipt(printer, job);
        } else {
          await printReceipt(printer, job);
        }
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

  poll();
  setInterval(poll, config.pollIntervalMs || 2000);
}

start();
