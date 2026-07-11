const express = require("express");
const fs = require("fs");
const cors = require("cors");
const { SerialPort } = require("serialport");

const app = express();
const PORT = 3000;

app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

const PRINTER_NAME = "POSPrinter";
const PRINTER_PATH = `\\\\localhost\\${PRINTER_NAME}`;

const DISPLAY_SETTINGS = {
    path: "COM2",
    baudRate: 2400,
    dataBits: 8,
    parity: "none",
    stopBits: 1,
    autoOpen: false
};

/*
   LED mode commands.
   If LEDs do not change, change MODE_SET from "S" to "Q".
*/
const MODE_SET = "S";

const DISPLAY_MODE_S = {
    PRICE:   Buffer.from([0x1B, 0x73, 0x31]),
    TOTAL:   Buffer.from([0x1B, 0x73, 0x32]),
    COLLECT: Buffer.from([0x1B, 0x73, 0x33]),
    CHANGE:  Buffer.from([0x1B, 0x73, 0x34])
};

const DISPLAY_MODE_Q = {
    PRICE:   Buffer.from([0x1B, 0x71, 0x31]),
    TOTAL:   Buffer.from([0x1B, 0x71, 0x32]),
    COLLECT: Buffer.from([0x1B, 0x71, 0x33]),
    CHANGE:  Buffer.from([0x1B, 0x71, 0x34])
};

const DISPLAY_MODE = MODE_SET === "Q" ? DISPLAY_MODE_Q : DISPLAY_MODE_S;

let displayQueue = Promise.resolve();

function queueDisplayTask(task) {
    displayQueue = displayQueue.then(task).catch(err => {
        console.error("Display queue error:", err.message);
    });
    return displayQueue;
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

app.get("/", (req, res) => {
    res.json({
        success: true,
        message: "La-Zogan POS service running",
        printer: PRINTER_PATH,
        display: DISPLAY_SETTINGS.path,
        baudRate: DISPLAY_SETTINGS.baudRate,
        modeSet: MODE_SET
    });
});

app.get("/status", (req, res) => {
    res.json({
        success: true,
        message: "Node print/display service running"
    });
});

/* ==============================
   CASH DRAWER
============================== */
app.get("/open-drawer", (req, res) => {
    const drawerCommand = Buffer.from([
        0x1B, 0x40,
        0x1B, 0x70, 0x00, 0x19, 0xFA
    ]);

    fs.writeFile(PRINTER_PATH, drawerCommand, (error) => {
        if (error) {
            return res.status(500).json({
                success: false,
                message: "Drawer open failed: " + error.message
            });
        }

        res.json({
            success: true,
            message: "Drawer opened successfully"
        });
    });
});

/* ==============================
   DISPLAY HELPERS
============================== */
function openDisplay(callback) {
    const port = new SerialPort(DISPLAY_SETTINGS);

    port.open((err) => {
        if (err) {
            callback(err, null);
            return;
        }

        callback(null, port);
    });
}

function writeBuffer(port, buffer, wait = 150) {
    return new Promise((resolve, reject) => {
        port.write(buffer, (err) => {
            if (err) return reject(err);

            port.drain((drainErr) => {
                if (drainErr) return reject(drainErr);
                setTimeout(resolve, wait);
            });
        });
    });
}

function formatDisplayAmount(amount) {
    const n = Number(amount) || 0;
    return n.toFixed(2).substring(0, 8).padStart(8, " ");
}

async function showAmount(port, label, amount) {
    const mode = String(label || "PRICE").toUpperCase();
    const value = formatDisplayAmount(amount);
    const modeCmd = DISPLAY_MODE[mode] || DISPLAY_MODE.PRICE;

    // Send LED mode first
    await writeBuffer(port, modeCmd, 100);

    // Send number
    await writeBuffer(port, Buffer.from(value + "\r", "ascii"), 300);
}

function runDisplayJob(job) {
    return new Promise((resolve) => {
        openDisplay(async (err, port) => {
            if (err) {
                return resolve({
                    success: false,
                    message: err.message
                });
            }

            try {
                await job(port);
                await delay(300);

                port.close(() => {
                    resolve({
                        success: true,
                        message: "Display updated"
                    });
                });
            } catch (e) {
                try {
                    port.close(() => {});
                } catch (_) {}

                resolve({
                    success: false,
                    message: e.message
                });
            }
        });
    });
}

/* ==============================
   DISPLAY TEST ROUTES
============================== */
app.get("/test-display", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "TOTAL", 2850);
        });
    });

    res.json(result || {
        success: true,
        message: "Test display queued"
    });
});

app.get("/test-price", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "PRICE", 1111);
        });
    });

    res.json(result || { success: true });
});

app.get("/test-total", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "TOTAL", 2222);
        });
    });

    res.json(result || { success: true });
});

app.get("/test-collect", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "COLLECT", 3333);
        });
    });

    res.json(result || { success: true });
});

app.get("/test-change", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "CHANGE", 4444);
        });
    });

    res.json(result || { success: true });
});

/* ==============================
   POS DISPLAY ROUTES
============================== */
app.post("/customer-display", (req, res) => {
    const amount = Number(req.body.amount) || 0;
    const label = req.body.label || "TOTAL";

    queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, label, amount);
        });
    });

    res.json({
        success: true,
        message: "Display command queued"
    });
});

app.post("/customer-display-pay", (req, res) => {
    const total = Number(req.body.total) || 0;
    const collect = Number(req.body.collect) || 0;
    const change = Number(req.body.change) || 0;

    queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            await showAmount(port, "TOTAL", total);
            await delay(1200);

            await showAmount(port, "COLLECT", collect);
            await delay(1200);

            await showAmount(port, "CHANGE", change);
        });
    });

    res.json({
        success: true,
        message: "Payment display sequence queued"
    });
});

app.get("/customer-display-clear", async (req, res) => {
    const result = await queueDisplayTask(() => {
        return runDisplayJob(async (port) => {
            // Reset display to 0.00
            await showAmount(port, 0);
        });
    });

    res.json(result || {
        success: true,
        message: "Customer display reset"
    });
});

app.listen(PORT, () => {
    console.log(`La-Zogan POS service running on http://localhost:${PORT}`);
    console.log(`Printer path: ${PRINTER_PATH}`);
    console.log(`Customer display port: ${DISPLAY_SETTINGS.path}`);
    console.log(`Baud rate: ${DISPLAY_SETTINGS.baudRate}`);
    console.log(`Mode set: ${MODE_SET}`);
});