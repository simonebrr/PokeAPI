#https://t.me/PokePrice_bot
from multiprocessing import context
from typing import List, Optional
import requests, re, time
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, ContextTypes, CallbackQueryHandler
import aiohttp # type: ignore
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

async def create_language_buttons(languages: List[str], card_data: str) -> Optional[InlineKeyboardMarkup]:
    if not languages:
        return None
    
    buttons = []
    for lang in languages:
        if lang in LANGUAGES:
            display_name = LANGUAGES.get(lang, lang)
            buttons.append(InlineKeyboardButton(display_name, callback_data=f"lang_{lang}_{card_data}"))
    
    return InlineKeyboardMarkup([buttons])


async def button_callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()

    if query.data.startswith("lang_"):
        _, selected_language, card_data = query.data.split("_", 2)
        name, card_id = card_data.split("|")
        
        base_url = "http://localhost:8080/card/"
        params = {
            "pokemon": name,
            "code": card_id
        }
        
        try:
            async with aiohttp.ClientSession() as session:
                async with session.get(base_url, params=params) as response:
                    if response.status != 200:
                        await query.message.reply_text(f"Error: Server returned status {response.status}")
                        return
                    
                    data = await response.json()
                    print(data[1])
                    price_lines = [
                        f"📍 Name: {name.capitalize()}",
                        f"🆔 ID: {card_id}",
                        f"🌐 Language: {LANGUAGES.get(selected_language, selected_language)}",
                        "\n💰 Prices:"
                    ]
                    
                    found_prices = False
                    prices_by_condition = {}

                    # Ottieni l'array di listings dal primo (e unico) ID carta
                    card_id_key = next(iter(data[1]))
                    listings = data[1][card_id_key]

                    for listing in listings:
                        properties = listing.get('properties_hash', {})
                        condition = properties.get('condition')
                        language = properties.get('pokemon_language')
                        price_cents = listing.get('price_cents')
                        
                        # Controlla la lingua e la condizione
                        if language == selected_language and condition in CONDITION:
                            if price_cents is not None:
                                price_eur = price_cents / 100
                                # Memorizza il prezzo più basso per ogni condizione
                                if condition not in prices_by_condition or price_eur < prices_by_condition[condition]:
                                    prices_by_condition[condition] = price_eur
                                    found_prices = True

                    if found_prices:
                        # Aggiungi i prezzi al messaggio nell'ordine di CONDITION
                        for condition in CONDITION:
                            if condition in prices_by_condition:
                                price_eur = prices_by_condition[condition]
                                price_lines.append(f"• {condition}: {price_eur:.2f}€")
                        
                        price_message = "\n".join(price_lines)
                        await query.message.reply_text(price_message)
                    else:
                        await query.message.reply_text(
                            f"No prices found for {name.capitalize()} in {LANGUAGES.get(selected_language, selected_language)}. "
                            f"Available in: {', '.join(set(listing['properties_hash']['pokemon_language'] for listing in listings))}"
                        )

        except aiohttp.ClientError as e:
            await query.message.reply_text(f"Network error: {str(e)}")
        except Exception as e:
            await query.message.reply_text(f"Unexpected error: {str(e)}")
    
    try:
        await query.message.delete()
    except Exception:
        pass

async def look_for(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """
    Search for a Pokemon card and display its information.
    Command format: /find <name> <id>
    """
    try:
        # Verifica degli argomenti
        if len(context.args) < 2:
            await update.message.reply_text("Usa il comando: /find <name> <id>")
            return

        # Parsing di name e card_id
        name = " ".join(context.args[:-1])
        card_id = context.args[-1]

        # Verifica del formato dell'ID
        match = re.match(r"(\d+)/(\d+)", card_id)
        if not match:
            await update.message.reply_text("Formato ID non valido. Usa 'numero/totale'.")
            return

        # Salva i dati della carta per la gestione callback
        card_data = f"{name}|{card_id}"
        context.user_data["card_data"] = card_data

        # Richiesta API
        base_url = "http://localhost:8080/card/"
        params = {"pokemon": name, "code": card_id}
        
        response = requests.get(base_url, params=params)
        response.raise_for_status()
        data = response.json()

        # Individua dinamicamente l'ID della carta (come 119617)
        card_key = next(iter(data[1].keys()), None)
        print(card_key)
        if not card_key or card_key not in data[1]:
            await update.message.reply_text("Errore: carta non trovata.")
            return

        # Estrazione delle lingue disponibili
        available_languages = list(
            {card["properties_hash"]["pokemon_language"] for card in data[1][card_key]}
        )

        # Creazione dei bottoni per la selezione della lingua
        reply_markup = await create_language_buttons(available_languages, card_data)
        if not reply_markup:
            await update.message.reply_text("Nessuna lingua disponibile per questa carta.")
            return

        # Invia l'immagine e i bottoni per la lingua
        await update.message.reply_photo(
            photo="https://www.cardtrader.com"+data[0],
            caption=f"Carta trovata!\nNome: {name}\nID: {card_id}\n\nSeleziona la lingua:",
            reply_markup=reply_markup
        )
        """await update.message.reply_text(
            text=f"Carta trovata!\nNome: {name.capitalize()}\nID: {card_id}\n\nSeleziona la lingua:",
            reply_markup=reply_markup
        )"""

    except requests.RequestException as e:
        await update.message.reply_text(f"Errore nel recupero dei dati: {str(e)}")
    except Exception as e:
        await update.message.reply_text(f"Errore di ricerca: {str(e)}")

def main():
    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    
    app.add_handler(CommandHandler("start", start))
    app.add_handler(CommandHandler("find", look_for))
    app.add_handler(CallbackQueryHandler(button_callback))

    print("Bot started and connected to database...")
    app.run_polling()

if __name__ == "__main__":
    main()