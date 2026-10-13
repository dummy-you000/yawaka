import os
import re
import subprocess
import shutil
import asyncio
from telethon import TelegramClient, events, Button

# ================= CONFIGURATION =================
# Safe environment reading from the host system profile.
# It falls back to your hardcoded keys if environment variables aren't set.
API_ID = int(os.environ.get("API_ID", 36920878))
API_HASH = os.environ.get("API_HASH", "3a0d6aed31669861d23f96375258e723")
BOT_TOKEN = os.environ.get("BOT_TOKEN", "8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk")
LOG_CHANNEL = int(os.environ.get("LOG_CHANNEL", -1004303490907))
# =================================================

bot = TelegramClient('site_dumper_bot', API_ID, API_HASH).start(bot_token=BOT_TOKEN)

# Temporary storage state for users waiting to dump websites
user_states = {}

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

    elif data == "menu_dump":
        user_states[user_id] = "WAITING_FOR_URL"
        await event.edit("Please send the website link you want to dump:\n(Supports http, https, and deep subfolders)")

@bot.on(events.NewMessage)
async def message_handler(event):
    user_id = event.sender_id
    sender = await event.get_sender()
    username = f"@{sender.username}" if sender.username else "No Username"
    first_name = sender.first_name if sender.first_name else "User"
    
    if user_id in user_states and user_states[user_id] == "WAITING_FOR_URL":
        url = event.text.strip()
        del user_states[user_id]

        # Validates any standard http or https layout including subdirectories
        if not re.match(r'^https?://[^\s/$.?#].[^\s]*$', url, re.IGNORECASE):
            await event.respond("Invalid link format. Ensure it begins with http:// or https://.\n\nUse /start to try again.")
            return

        # 1. Inform the user that the process has initiated
        status_msg = await event.respond(
            "Dumping please wait...\n\n"
            "Go scroll on tiktok this take time it depends on website file the bot is scraping the front&backend file."
        )
        
        # 2. Immediately forward the user's details and target link to your connected channel
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
                print(f"Failed to send log to channel. Make sure the bot is an admin in the channel. Error: {log_error}")
        
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
            "--header=Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "--header=Accept-Language: en-US,en;q=0.5",
            f"--header=Referer: {url}",
            "--user-agent=Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15",
            "--execute", "robots=off",
            "-P", folder_name,
            url
        ]

        try:
            # 3. Process the script download task in the background safely
            process = await asyncio.create_subprocess_exec(
                *wget_command,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL
            )
            await process.wait()

            # Ensure the bot connection is re-established if the server paused network operations during downloading
            if not bot.is_connected():
                await bot.connect()

            # 4. Compile download directory to compressed format
            if os.path.exists(folder_name) and os.listdir(folder_name):
                try:
                    await status_msg.edit("Dump complete. Archiving files...")
                except Exception:
                    pass
                
                shutil.make_archive(f"dump_{user_id}", 'zip', folder_name)
                full_zip_path = f"dump_{user_id}.zip"

                # 5. Send ZIP archive file package directly back to user
                caption_text = f"Website Dump Successful.\n\nTarget Link: {url}"
                await bot.send_file(event.chat_id, full_zip_path, caption=caption_text)
                
                # 6. Post final archive update validation to the logging channel
                try:
                    await bot.send_file(LOG_CHANNEL, full_zip_path, caption=f"Archive file backup compiled for: {url}")
                except Exception as file_log_error:
                    print(f"Failed to send archive to channel: {file_log_error}")

                if os.path.exists(full_zip_path):
                    os.remove(full_zip_path)
                
                # Clean up the status tracking message block silently after complete package execution
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
        
        finally:
            if os.path.exists(folder_name):
                shutil.rmtree(folder_name)

print("System running in professional mode...")
bot.run_until_disconnected()
