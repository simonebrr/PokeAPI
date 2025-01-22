#https://t.me/PokePrice_bot
from multiprocessing import context
from typing import List, Optional
import requests, re
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, ContextTypes, CallbackQueryHandler

TELEGRAM_BOT_TOKEN = "8077459438:AAGmeqc3ZLrJplrlXCfd27AXQspwWPkfHY0"

# Language mapping
LANGUAGES = {
    "it": "Italian 🇮🇹",
    "en": "English 🇬🇧"
}

CONDITION = ["Mint", "Near Mint", "Slightly Played", "Moderately Played", "Played", "Poor"]

if not TELEGRAM_BOT_TOKEN:
    raise ValueError("TELEGRAM_BOT_TOKEN environment variable is not set")

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    welcome_message = (
        "👋 Welcome to the Pokemon Card Price Bot!\n\n"
        "To search for a card, use the command:\n"
        "/find [name] [number/total]\n\n"
        "📝 Examples:\n"
        "• /find Terapagos ex 170/142\n"
        "• /find Alcremie VMAX 073/072\n"
        "• /find Mewtwo 10/102\n\n"
        "ℹ️ Enter the card name and its number in the set"
    )
    await update.message.reply_text(welcome_message)

async def create_language_buttons(languages: List[str]) -> Optional[InlineKeyboardMarkup]:
    if not languages:
        return None
    
    buttons = []
    for lang in languages:
        display_name = LANGUAGES.get(lang, lang)
        buttons.append(InlineKeyboardButton(display_name, callback_data=f"lang_{lang}_{context.user_data['card_data']}"))
    
    return InlineKeyboardMarkup([buttons])

async def button_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()

    if query.data.startswith("lang_"):
        # Extract language and card data from callback
        _, selected_language, card_data = query.data.split("_", 2)
        
        # Parse the stored card data
        name, card_id = card_data.split("|")
        
        # Fetch card data with selected language
        base_url = "http://localhost:8080/card/"
        params = {
            "pokemon": name,
            "code": card_id,
            "language": selected_language
        }
        
        try:
            response = requests.get(base_url, params=params)
            response.raise_for_status()
            data = response.json()
            
            # Build a single message with all prices
            price_message = (
                f"📍 Name: {name}\n"
                f"🆔 ID: {card_id}\n"
                f"🌐 Language: {LANGUAGES.get(selected_language, selected_language)}\n\n"
                f"💰 Prices:\n"
            )
            
            # Add prices for each condition
            for condition in CONDITION:
                price = data.get('price', {}).get(condition, 'N/A')
                price_message += f"• {condition}: {price}€\n"
            
            # Send card image with prices in caption
            await query.message.reply_photo(
                photo=data['url'],
                caption=price_message
            )
                
        except requests.RequestException as e:
            await query.message.reply_text(f"Error retrieving prices: {str(e)}")
    
    # Delete the original message with language selection
    await query.message.delete()

async def look_for(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Search for a Pokemon card and display its information.
    Command format: /find <name> <id>
    """
    query = update.callback_query

    try:
        # Validate input arguments
        if len(context.args) < 2:
            await update.message.reply_text("Usage: /find name number")
            return
        
        # Parse name and ID
        name = " ".join(context.args[:-1])
        card_id = context.args[-1]
        
        # Validate ID format
        print(name, card_id)
        """match = re.match(r"(\d+)/(\d+)", card_id)
        if not match:
            await update.message.reply_text(
                f"Card found!\nName: {name}\nID: {card_id}"
            )
            return"""
        time.sleep(19)
            
        # Store card data in user_data for callback handling
        context.user_data['card_data'] = f"{name}|{card_id}"
            
        # Fetch card data
        base_url = "http://localhost:8080/card/"
        params = {
            "pokemon": name,
            "code": card_id
        }
        
        try:
            response = requests.get(base_url, params=params)
            response.raise_for_status()
            data = response.json()
        except requests.RequestException as e:
            await query.message.reply_text(f"Error retrieving data: {str(e)}")
            return
            
        # Get available languages
        available_languages = data.get("editable_properties", [])
        reply_markup = await create_language_buttons(available_languages)
        if not reply_markup:
            await update.message.reply_text("No languages available.")
            return
            
        # Send card image with language selection
        await update.message.reply_photo(
            photo=data['url'],
            caption=f"Card found!\nName: {name}\nID: {card_id}\n\nSelect language:",
            reply_markup=reply_markup
        )
            
    except Exception as e:
        await update.message.reply_text(f"Search error: {str(e)}")

def main():
    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("find", look_for))
    app.add_handler(CallbackQueryHandler(button_callback))

    print("Bot started and connected to database...")
    app.run_polling()

if __name__ == "__main__":
    main()