import os
import re
import subprocess
import shutil
import asyncio
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from telethon import TelegramClient, events, Button

# ================= CONFIGURATION =================
API_ID = int(os.environ.get("API_ID", 36920878))
API_HASH = os.environ.get("API_HASH", "3a0d6aed31669861d23f96375258e723")
BOT_TOKEN = os.environ.get("BOT_TOKEN", "8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk")
LOG_CHANNEL = int(os.environ.get("LOG_CHANNEL", -1004303490907))
# =================================================

# --- LIGHTWEIGHT WEB SERVER FOR RENDER HEALTH CHECKS ---
class HealthCheckHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-type", "text/plain")
        self.end_headers()
        self.wfile.write(b"Bot is alive and running smoothly.")

    def log_message(self, format, *args):
        return  # Pinapatay ang spammy console logs mula sa health check pings

def run_health_server():
    try:
        port = int(os.environ.get("PORT", 10000))
        server = HTTPServer(("0.0.0.0", port), HealthCheckHandler)
        print(f"Health check web server active on port {path_port if 'path_port' in locals() else port}...")
        server.serve_forever()
    except Exception as e:
        print(f"Web server error: {e}")
# --------------------------------------------------------

# Ininitialize ang client nang walang rigid pre-constructed network block boundaries
bot = TelegramClient('site_dumper_bot', API_ID, API_HASH)

user_states = {}

def get_dir_size(path):
    total_size = 0
    if os.path.exists(path):
        for dirpath, dirnames, filenames in os.walk(path):
            for f in filenames:
                fp = os.path.join(dirpath, f)
                if os.path.exists(fp):
                    total_size += os.path.getsize(fp)
    return total_size

async def size_guard(folder_name, process):
    # 20MB limit in bytes (20 * 1024 * 1024)
    MAX_BYTES = 20971520 
    while True:
        await asyncio.sleep(2)
        if process.returncode is not None:
            break
        if os.path.exists(folder_name):
            if get_dir_size(folder_name) >= MAX_BYTES:
                try:
                    process.terminate()  # Pwersahang pinapatay ang wget kapag lumampas sa 20MB
                except Exception:
                    pass
                break

@bot.on(events.NewMessage(pattern='/start'))
async def start_handler(event):
    sender = await event.get_sender()
    first_name = sender.first_name if sender.first_name else "User"
    
    welcome_text = (
        f"Welcome, {first_name}.\n\n"
        "Please select an option below to interact with the system or contact support if you encounter any system errors."
    )
    
    buttons = [
        [Button.inline("Dump Website", data="menu_dump")],
        [Button.inline("View Owner / Support", data="menu_owner")]
    ]
    await event.respond(welcome_text, buttons=buttons)
    raise events.StopPropagation

@bot.on(events.CallbackQuery)
async def callback_handler(event):
    data = event.data.decode('utf-8')
    user_id = event.sender_id

    if data == "menu_owner":
        await event.edit(
            "System Support & Owner Information\n\n"
            "If you have encountered an operational error, please contact the system.\n"
            "Administrator: @cosmo64bit\n\n"
            "Use /start to return to the main menu."
        )
        raise events.StopPropagation

    elif data == "menu_dump":
        user_states[user_id] = "WAITING_FOR_URL"
        await event.edit("Please send the website link you want to dump:\n(Supports http, https, and deep subfolders)")
        raise events.StopPropagation

@bot.on(events.NewMessage)
async def message_handler(event):
    user_id = event.sender_id
    sender = await event.get_sender()
    username = f"@{sender.username}" if sender.username else "No Username"
    first_name = sender.first_name if sender.first_name else "User"
    
    if event.text.startswith('/start'):
        return

    if user_id in user_states and user_states[user_id] == "WAITING_FOR_URL":
        url = event.text.strip()
        del user_states[user_id]

        if not (url.lower().startswith("http://") or url.lower().startswith("https://")):
            await event.respond("Invalid link format. Ensure it begins with http:// or https://.\n\nUse /start to try again.")
            return

        status_msg = await event.respond(
            "Dumping please wait...\n\n"
            "Go scroll on tiktok this take time it depends on website file the bot is scraping the front&backend file."
        )
        
        channel_log = (
            "New Website Dump Request\n\n"
            f"User ID: {user_id}\n"
            f"Name: {first_name}\n"
            f"Username: {username}\n"
            f"Target URL: {url}"
        )
        
        if bot.is_connected():
            try:
                await bot.send_message(LOG_CHANNEL, channel_log)
            except Exception as log_error:
                print(f"Failed to send log to channel. Error: {log_error}")
        
        folder_name = f"dump_{user_id}"
        zip_name = f"dump_{user_id}.zip"

        if os.path.exists(folder_name): shutil.rmtree(folder_name)
        if os.path.exists(zip_name): os.remove(zip_name)
        
        os.makedirs(folder_name, exist_ok=True)

        wget_command = [
            "wget",
            "--recursive",
            "--level=3",
            "--page-requisites",
            "--adjust-extension",
            "--convert-links",
            "--span-hosts=off",
            "--no-parent",
            "--wait=2",
            "--random-wait",
            "--limit-rate=120k",
            "--quota=20m",  # Limit sa 20MB quota download pipeline
            "--header=Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "--header=Accept-Language: en-US,en;q=0.5",
            f"--header=Referer: {url}",
            "--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit=605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15",
            "--execute", "robots=off",
            "-P", folder_name,
            url
        ]

        try:
            process = await asyncio.create_subprocess_exec(
                *wget_command,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL
            )

            guard_task = asyncio.create_task(size_guard(folder_name, process))
            
            await process.wait()
            guard_task.cancel()  

            if not bot.is_connected():
                await bot.connect()

            final_size = get_dir_size(folder_name)

            if os.path.exists(folder_name) and os.listdir(folder_name):
                if final_size >= 20971520:
                    try:
                        await status_msg.edit("⚠️ **Scraping Paused:** The size limit reached the **20MB max size safety threshold**. Compiling what was collected so far...")
                    except Exception:
                        pass
                else:
                    try:
                        await status_msg.edit("Dump complete. Compiling source files to ZIP package format...")
                    except Exception:
                        pass
                
                shutil.make_archive(f"dump_{user_id}", 'zip', folder_name)
                full_zip_path = f"dump_{user_id}.zip"

                caption_text = f"Website Dump Successful.\n\nTarget Link: {url}"
                await bot.send_file(event.chat_id, full_zip_path, caption=caption_text)
                
                try:
                    await bot.send_file(LOG_CHANNEL, full_zip_path, caption=f"Archive file backup compiled for: {url}")
                except Exception as file_log_error:
                    print(f"Failed to send archive to channel: {file_log_error}")

                if os.path.exists(full_zip_path):
                    os.remove(full_zip_path)
                
                try:
                    await status_msg.delete()
                except Exception:
                    pass
            else:
                try:
                    await status_msg.edit("Dump failed. No assets collected. The domain may be offline or protected.")
                except Exception:
                    await event.respond("Dump failed. No assets collected. The domain may be offline or protected.")

        except Exception as e:
            if not bot.is_connected():
                await bot.connect()
            try:
                await status_msg.edit(f"An unexpected error occurred during processing.\nError Details: {str(e)}")
            except Exception:
                await event.respond(f"An unexpected error occurred during processing.\nError Details: {str(e)}")
        
        if os.path.exists(folder_name):
            shutil.rmtree(folder_name)
    
    raise events.StopPropagation

# PANGUNAHING ENGINE LIFECYCLE RUNNER
if __name__ == '__main__':
    # 1. Patatakbuhin ang Render Health Server sa background gamit ang daemon thread
    web_thread = threading.Thread(target=run_health_server, daemon=True)
    web_thread.start()

    # 2. Patatakbuhin ang Telethon Client sa main execution thread nang walang structural block conflicts
    print("System running in professional mode with async decoupling patches active...")
    bot.start(bot_token=BOT_TOKEN)
    bot.run_until_disconnected()
