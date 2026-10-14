import os
import asyncio
import logging
import shutil
import threading
import socket
import ipaddress
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse
from telethon import TelegramClient, events, Button

# ================= CONFIGURATION =================
# INAYOS: Ibinalik sa tamang Env Variable Keys at ginawang default values ang iyong mga credentials
API_ID = int(os.environ.get("API_ID", 36920878))
API_HASH = os.environ.get("API_HASH", "3a0d6aed31669861d23f96375258e723")
BOT_TOKEN = os.environ.get("BOT_TOKEN", "8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk")
LOG_CHANNEL = int(os.environ.get("LOG_CHANNEL", -1004303490907))

PORT = int(os.environ.get("PORT", "10000"))
PROXY_SERVER = os.environ.get("PROXY_SERVER", "")
MAX_BYTES = 20 * 1024 * 1024
# =================================================

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
logger = logging.getLogger("cosmo_bot")

# Setup global loop configuration para sa Render
loop = asyncio.new_event_loop()
asyncio.set_event_loop(loop)

bot = TelegramClient("site_dumper_bot", API_ID, API_HASH, loop=loop)

user_states = {}

# ================= HEALTH CHECK SERVER =================
class HealthCheckHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path not in ("/", "/health"):
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"Bot service is running.")

    def log_message(self, fmt, *args):
        pass

def run_health_server():
    try:
        server = HTTPServer(("0.0.0.0", PORT), HealthCheckHandler)
        logger.info("Health server listening on port %s", PORT)
        server.serve_forever()
    except Exception:
        logger.exception("Health server failed")

# ================= HELPERS =================
def get_dir_size(path):
    total = 0
    if not os.path.isdir(path):
        return total
    for root, _, files in os.walk(path):
        for filename in files:
            filepath = os.path.join(root, filename)
            try:
                total += os.path.getsize(filepath)
            except OSError:
                pass
    return total

async def send_log(message):
    if not LOG_CHANNEL:
        return
    try:
        await bot.send_message(LOG_CHANNEL, message)
    except Exception:
        logger.exception("Could not send log to Telegram channel")

# ================= START COMMAND =================
@bot.on(events.NewMessage(pattern=r"^/start(?:@\w+)?$"))
async def start_handler(event):
    sender = await event.get_sender()
    first_name = getattr(sender, "first_name", None) or "User"

    buttons = [
        [Button.inline("Dump Website", data=b"menu_dump")],
        [Button.inline("View Owner / Support", data=b"menu_owner")],
    ]

    await event.respond(
        f"Welcome, {first_name}!\n\nSelect an option below.",
        buttons=buttons,
    )

# ================= BUTTON HANDLERS =================
@bot.on(events.CallbackQuery)
async def callback_handler(event):
    data = event.data
    user_id = event.sender_id

    if data == b"menu_owner":
        await event.answer()
        await event.edit(
            "System Support & Owner Information\n\n"
            "Administrator: @cosmo64bit\n\n"
            "Send /start to return to the main menu."
        )
    elif data == b"menu_dump":
        await event.answer()
        user_states[user_id] = "WAITING_FOR_URL"
        await event.edit(
            "Send the URL of a website you want to archive.\n\n"
            "Example: https://example.com"
        )
    else:
        await event.answer("Unknown option.", alert=True)

# ================= MESSAGE HANDLER (IDINAGDAG) =================
# Kakalapin nito ang text input ng user kapag naghihintay ang bot ng URL link
@bot.on(events.NewMessage)
async def message_handler(event):
    user_id = event.sender_id
    text = event.text.strip() if event.text else ""

    if text.startswith('/start'):
        return

    if user_id in user_states and user_states[user_id] == "WAITING_FOR_URL":
        url = text
        del user_states[user_id] # Burahin agad ang state para iwas loop/spam

        if not (url.lower().startswith("http://") or url.lower().startswith("https://")):
            await event.respond("Invalid link format. Ensure it begins with http:// or https://.\n\nUse /start to try again.")
            return

        # Ipasa sa download function ang event at detalye
        await download_website(event, url, user_id)

# ================= WEBSITE DOWNLOAD =================
async def download_website(event, url, user_id):
    folder_name = f"dump_{user_id}"
    zip_name = f"dump_{user_id}.zip"
    status_msg = None

    try:
        wget_path = shutil.which("wget")
        if not wget_path:
            await event.respond("Download failed: wget is not installed. Check your Render Environment.")
            return

        status_msg = await event.respond("Download started. Please wait...")

        # I-verify kung valid at public IP ang host destination
        hostname = urlparse(url).hostname
        try:
            addresses = await asyncio.get_running_loop().getaddrinfo(hostname, None, type=socket.SOCK_STREAM)
            for address in addresses:
                ip = ipaddress.ip_address(address[4][0])
                if not ip.is_global:
                    await status_msg.edit("Download rejected: the website resolves to a non-public IP address.")
                    return
        except Exception:
            await status_msg.edit("Invalid domain name or cannot resolve host address.")
            return

        if os.path.exists(folder_name): shutil.rmtree(folder_name)
        if os.path.exists(zip_name): os.remove(zip_name)
        os.makedirs(folder_name, exist_ok=True)

        command = [
            wget_path,
            "--recursive",
            "--level=3",
            "--page-requisites",
            "--adjust-extension",
            "--convert-links",
            "--no-parent",
            "--wait=2",
            "--random-wait",
            "--limit-rate=120k",
            "--quota=20m",
            "--timeout=20",
            "--tries=2",
            "--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15",
            "-P", folder_name,
            url,
        ]

        env = os.environ.copy()
        if PROXY_SERVER:
            env["http_proxy"] = PROXY_SERVER
            env["https_proxy"] = PROXY_SERVER

        await send_log(f"Website archive request\nUser ID: {user_id}\nTarget: {url}")

        process = await asyncio.create_subprocess_exec(
            *command,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.PIPE,
            env=env,
        )

        try:
            # Subaybayan ang size habang nagda-download (Size Guard)
            while process.returncode is None:
                await asyncio.sleep(2)
                if get_dir_size(folder_name) > MAX_BYTES:
                    process.terminate()
                    await status_msg.edit("⚠️ Download stopped: 20 MB size limit was reached.")
                    break
            
            _, stderr_data = await asyncio.wait_for(process.communicate(), timeout=300)
        except asyncio.TimeoutError:
            process.kill()
            await process.communicate()
            await status_msg.edit("Download timed out after 5 minutes.")
            return

        size = get_dir_size(folder_name)
        if size == 0 or not os.listdir(folder_name):
            await status_msg.edit("No files were downloaded. Check the URL or connection.")
            return

        shutil.make_archive(f"dump_{user_id}", "zip", root_dir=folder_name)

        if not os.path.isfile(zip_name):
            await status_msg.edit("Could not create the ZIP archive.")
            return

        await event.respond(file=zip_name, message=f"Website archive created.\nTarget: {url}")
        await send_log(f"Website archive completed\nUser ID: {user_id}\nTarget: {url}\nSize: {os.path.getsize(zip_name)} bytes")
        await status_msg.delete()

    except Exception as e:
        logger.exception("Website archive operation failed")
        if status_msg:
            await status_msg.edit(f"An error occurred: {str(e)}")
    finally:
        if os.path.exists(folder_name): shutil.rmtree(folder_name)
        if os.path.exists(zip_name): os.remove(zip_name)

# ================= POLLING MANAGEMENT =================
async def main():
    await bot.start(bot_token=BOT_TOKEN)
    logger.info("Bot successfully linked to core service loop.")
    await bot.run_until_disconnected()

if __name__ == '__main__':
    web_thread = threading.Thread(target=run_health_server, daemon=True)
    web_thread.start()
    loop.run_until_complete(main())
