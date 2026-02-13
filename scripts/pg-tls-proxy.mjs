#!/usr/bin/env node
import net from "node:net";
import tls from "node:tls";

const listenHost = process.env.PG_PROXY_LISTEN_HOST || "127.0.0.1";
const listenPort = Number(process.env.PG_PROXY_LISTEN_PORT || "6543");
const remoteHost = process.env.PG_PROXY_REMOTE_HOST;
const remotePort = Number(process.env.PG_PROXY_REMOTE_PORT || "5432");
const rejectUnauthorized = String(process.env.PG_PROXY_REJECT_UNAUTHORIZED || "true") === "true";

if (!remoteHost) {
  console.error("Missing PG_PROXY_REMOTE_HOST.");
  process.exit(1);
}

const server = net.createServer((clientSocket) => {
  clientSocket.pause();
  const upstreamPlain = net.connect({ host: remoteHost, port: remotePort });

  const closeClient = () => clientSocket.destroy();
  const closeUpstream = () => upstreamPlain.destroy();
  clientSocket.on("error", closeUpstream);
  upstreamPlain.on("error", closeClient);

  upstreamPlain.once("connect", () => {
    // PostgreSQL SSLRequest packet: length(8) + code(80877103)
    const sslRequest = Buffer.alloc(8);
    sslRequest.writeInt32BE(8, 0);
    sslRequest.writeInt32BE(80877103, 4);
    upstreamPlain.write(sslRequest);
  });

  upstreamPlain.once("data", (response) => {
    if (!response.length || response[0] !== 0x53) {
      console.error("[pg-tls-proxy] remote server did not accept SSLRequest.");
      clientSocket.destroy();
      upstreamPlain.destroy();
      return;
    }

    const upstreamTls = tls.connect(
      {
        socket: upstreamPlain,
        rejectUnauthorized,
        servername: remoteHost,
      },
      () => {
        clientSocket.pipe(upstreamTls);
        upstreamTls.pipe(clientSocket);
        clientSocket.resume();
      },
    );

    const closeBoth = () => {
      clientSocket.destroy();
      upstreamTls.destroy();
    };

    clientSocket.on("error", closeBoth);
    upstreamTls.on("error", (err) => {
      console.error(`[pg-tls-proxy] upstream tls error: ${err.message}`);
      closeBoth();
    });
    clientSocket.on("end", () => upstreamTls.end());
    upstreamTls.on("end", () => clientSocket.end());
  });
});

server.on("error", (err) => {
  console.error(`[pg-tls-proxy] ${err.message}`);
  process.exit(1);
});

server.listen(listenPort, listenHost, () => {
  console.log(
    `[pg-tls-proxy] listening on ${listenHost}:${listenPort} -> tls://${remoteHost}:${remotePort} (verify_cert=${rejectUnauthorized})`,
  );
});
