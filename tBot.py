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

# Funzione per il comando /start
async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(
        "Benvenuto nel bot delle carte Pokemon! Usa /cerca nome numero per cercare una carta nel database"
    )

async def create_language_buttons(languages: List[str]) -> Optional[InlineKeyboardMarkup]:
    """Create inline keyboard markup with language buttons."""
    if not languages:
        return None
    
    keyboard = [[InlineKeyboardButton(lang, callback_data=f"lang_{lang}") 
                 for lang in languages]]
    return InlineKeyboardMarkup(keyboard)

# Handle button press
async def button_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()

    # Delete the original message
    await query.message.delete()



async def look_for(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Search for a Pokemon card and display its information.
    Command format: /cerca <name> <id>
    """
    try:
        # Validate input arguments
        if len(context.args) < 2:
            await update.message.reply_text("Usa il formato: /cerca nome numero")
            return
        
        # Parse name and ID
        name = " ".join(context.args[:-1])
        card_id = context.args[-1]
        
        # Validate ID format
        match = re.match(r"(\d+)/(\d+)", card_id)
        if not match:
            await update.message.reply_text(
                f"Carta trovata!\nNome: {name}\nID: {card_id}"
            )
            return
            
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
            await update.message.reply_text(f"Errore nel recupero dei dati: {str(e)}")
            return
            
        # Get available languages
        available_languages = data.get("editable_properties", [])
        reply_markup = await create_language_buttons(available_languages)
        
        if not reply_markup:
            await update.message.reply_text("Nessuna lingua disponibile.")
            return
            
        # Send card image with language selection
        await update.message.reply_photo(
            photo=data['url'],
            caption=f"Carta trovata!\nNome: {name}\nID: {card_id}\n\nSeleziona la lingua:",
            reply_markup=reply_markup
        )
        
        # Define conditions and their corresponding prices
        CONDITION_MAPPING = {
            "nearmint": "Nearmint Price",
            "poor": "Poor Price",
            "used": "Used Price"
        }
        
        # Process conditions and prices
        for condition in data.get("editable_properties", []):
            if condition not in CONDITION_MAPPING:
                continue
                
            params["condition"] = condition
            price = data.get('price', 'N/A')
            
            price_message = (
                f"Name: {name}\n"
                f"ID: {card_id}\n"
                f"Language: {params.get('language', 'N/A')}\n"
                f"Condition: {condition}\n"
                f"{CONDITION_MAPPING[condition]}: {price}"
            )
            
            await update.message.reply_text(price_message)
            
    except Exception as e:
        await update.message.reply_text(f"Errore nella ricerca: {str(e)}")

def main():
    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("cerca", look_for))
    app.add_handler(CallbackQueryHandler(button_callback))

    print("Bot avviato e connesso al database...")
    app.run_polling()

if __name__ == "__main__":
    main()

