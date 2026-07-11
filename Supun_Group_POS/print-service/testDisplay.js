const { SerialPort } = require("serialport");

const port = new SerialPort({
    path: "COM2",
    baudRate: 2800,
    dataBits: 8,
    parity: "none",
    stopBits: 1
});

function send(buf, name) {
    setTimeout(() => {
        console.log("Testing:", name, buf);
        port.write(buf);
    }, 1000);
}

port.on("open", () => {
    console.log("COM2 opened");

    // Test 1: normal ASCII
    send(Buffer.from("2750.00\r", "ascii"), "ASCII 2750.00");

    // after 3 seconds, test with spaces
    setTimeout(() => {
        send(Buffer.from(" 2750.00\r", "ascii"), "ASCII space 2750.00");
    }, 3000);

    // after 6 seconds, test with no dot
    setTimeout(() => {
        send(Buffer.from("275000\r", "ascii"), "ASCII 275000");
    }, 6000);

    // after 9 seconds, close
    setTimeout(() => {
        port.close();
        console.log("closed");
    }, 10000);
});