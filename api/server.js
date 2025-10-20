/**
 * WPOS Websocket update relay, node.js sever.
 * @type {*}
 */

//var fs = require('fs');
/*var options = {
    key: fs.readFileSync('/etc/apache2/certs/wallacepos.com-ssl-wildcard.key').toString(),
    cert: fs.readFileSync('/etc/apache2/certs/wallacepos-com-ssl-wildcard.crt').toString(),
    ca: fs.readFileSync('/etc/apache2/certs/sub.class2.code.ca.crt').toString()
};*/
var http = require('http');
var fs = require('fs');
var config = null;
var configpath = __dirname+'/../docs/.config.json';

function wshandler(req, res) {
    // Basic health endpoint and default 404
    if (req.url === '/healthz') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok' }));
        return;
    }
    res.writeHead(404);
    res.end();
}

var app = http.createServer(wshandler);

if (fs.existsSync(configpath))
    config = JSON.parse(fs.readFileSync(configpath, 'utf8'));
var port = (config && config.hasOwnProperty('feedserver_port')) ? config.feedserver_port : 8080;
// Bind to all interfaces by default for containerized setups; allow override via FEED_BIND
var ip = process.env.FEED_BIND || ((config && config.feedserver_proxy===true) ? '127.0.0.1' : '0.0.0.0');
var hashkey = (config && config.hasOwnProperty('feedserver_key')) ? config.feedserver_key : "5d40b50e172646b845640f50f296ac3fcbc191a7469260c46903c43cc6310ace"; // key for php interaction, provides extra security

app.listen(port, ip);

// socket.io v4 server
const { Server } = require('socket.io');
const io = new Server(app, {
    // Allow same-origin by default; adjust CORS as needed
    cors: { origin: true, methods: ["GET","POST"] }
});

var devices = {};
var sessions = {};

io.sockets.on('connection', function (socket) {
    // START AUTHENTICATION
    var cookies = null;
    var authed = false;
    // check for session cookie
    if (socket.handshake.hasOwnProperty('headers')) {
        if (socket.handshake.headers.hasOwnProperty('cookie')) {
            cookies = socket.handshake.headers.cookie;
            if (cookies.indexOf("PHPSESSID=") !== -1) { // trim up to our cookie value
                cookies = cookies.substr(cookies.indexOf("PHPSESSID=") + 10, cookies.length);
                if (cookies.indexOf(";") !== -1) { // trim off other cookies
                    cookies = cookies.substr(0, cookies.indexOf(";"));
                }
            }
            if (sessions.hasOwnProperty(cookies)) {
                authed = true;
                // Request device registration
                socket.emit('updates', {a: "regreq", data: ""});
                console.log("Authorised by session: " + cookies);
            }
        }
    }
    // check for hashkey (for php authentication)
    if (!authed) {
        if (socket.handshake.query.hasOwnProperty('hashkey')) {
            // accept connections from private networks if enabled
            var addr = socket.handshake.address || (socket.request && socket.request.connection && socket.request.connection.remoteAddress);
            var trustPrivate = (process.env.FEED_TRUST_PRIVATE || 'true').toLowerCase() === 'true';
            var isPrivate = function(a){
                if (!a) return false;
                // normalize IPv6-mapped IPv4
                if (a.startsWith('::ffff:')) a = a.replace('::ffff:', '');
                return (
                    a === '127.0.0.1' || a === '::1' ||
                    a.startsWith('10.') ||
                    a.startsWith('192.168.') ||
                    (a.startsWith('172.') && (function(){ var p = parseInt(a.split('.')[1],10); return p>=16 && p<=31; })())
                );
            };
            if ((hashkey == socket.handshake.query.hashkey) && (!trustPrivate || isPrivate(addr))) {
                authed = true;
                console.log("Authorised by hashkey from "+addr);
            }
        }
    }
    // Disconnect if not authenticated
    if (!authed) {
        socket.emit('updates', {a: "error", data: {code: "auth", message: "Socket authentication failed!"}});
        socket.disconnect();
    }

    // broadcast to all connected sockets
    socket.on('broadcast', function (data) {
        socket.broadcast.emit('updates', data);
    });

    // send to certain auth'd devices based on device id's provided.
    socket.on('send', function (data) {
        // if device.include is null, send to all auth'd
        var inclall = data.include == null;
        for (var i in devices) {
            if (inclall || (data.include && data.include.hasOwnProperty(i))) {
                var sid = devices[i].socketid;
                var sock = io.sockets.sockets.get(sid);
                if (sock) sock.emit('updates', data.data);
            } else {
                console.log(i + " not in devicelist, " + JSON.stringify(data.include) + "; discarding.");
            }
        }
        // send to the admin dash
        if (devices.hasOwnProperty(0)) {
            var asid = devices[0].socketid;
            var asock = io.sockets.sockets.get(asid);
            if (asock) asock.emit('updates', data.data);
        }
    });

    socket.on('session', function (data) {
        // check for hashkey
        if (hashkey == data.hashkey) {
            if (data.remove==false){
                sessions[data.data] = true;
                console.log("Added PHP session: " + data.data);
            } else {
                if (sessions.hasOwnProperty(data.data)){
                    delete(sessions[data.data]);
                    console.log("Removed PHP session: " + data.data);
                }
            }
        } else {
            console.log("Send request not processed, no valid hashkey!");
        }
    });

    socket.on('hashkey', function (data) {
        // check for hashkey
        if (hashkey == data.hashkey) {
            hashkey = data.newhashkey;
        } else {
            console.log("Send request not processed, no valid hashkey!");
        }
    });

    // register device details
    socket.on('reg', function (request) {
        // register device
        devices[request.deviceid] = {};
        devices[request.deviceid].socketid = socket.id;
        devices[request.deviceid].username = request.username;
        // remove device on disconnect
        socket.on('disconnect', function () {
            delete(devices[request.deviceid]);
            if (request.deviceid != 0) {
                if (devices.hasOwnProperty(0)) {
                    var dsid = devices[0].socketid;
                    var dsock = io.sockets.sockets.get(dsid);
                    if (dsock) dsock.emit('updates', {a: "devices", data: JSON.stringify(devices)});
                }
            }
        });
        if (devices.hasOwnProperty(0)) {
            var dsid = devices[0].socketid;
            var dsock = io.sockets.sockets.get(dsid);
            if (dsock) dsock.emit('updates', {a: "devices", data: JSON.stringify(devices)});
        }
        console.log("Device registered");
    });
});
