from flask import Flask, request, jsonify
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from functools import wraps
import time
import threading
import requests
import sys
import os
import logging

# Set up logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# Configuration
class Config:
    CHROMEDRIVER_PATH = os.environ.get('CHROMEDRIVER_PATH', 
        "./chromedriver-win64/chromedriver-win64/chromedriver.exe")
    MAX_WAIT_TIME = 10
    RESULT_WAIT_TIME = 2
    FLASK_STARTUP_DELAY = 2



def create_driver():
    """Create and configure a Chrome WebDriver instance."""
    try:
        chrome_options = Options()
        chrome_options.add_argument("--headless")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--disable-gpu")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--window-position=0,0")
        chrome_options.add_argument("--disable-extensions")
        chrome_options.add_argument("--remote-debugging-port=9222")

        service = Service(Config.CHROMEDRIVER_PATH)
        logger.info(f"Initializing WebDriver with path: {Config.CHROMEDRIVER_PATH}")
        
        driver = webdriver.Chrome(service=service, options=chrome_options)
        return driver
    except Exception as e:
        logger.error(f"Failed to create WebDriver: {str(e)}")
        raise

def switch_language_interactive(driver, wait):
    """
    Interactively switch the website language using the dropdown menu.
    Filters and logs only specific languages, waiting for user input.
    
    Args:
        driver: Selenium WebDriver instance
        wait: WebDriverWait instance
    
    Returns:
        bool: True if language switch was successful, False otherwise
    """
    try:
        # Wait for and click the language dropdown button
        logger.info("Looking for language dropdown...")
        dropdown_button = wait.until(
            EC.element_to_be_clickable((By.CSS_SELECTOR, ".btn.btn-sm.btn-outline-light.text-primary.dropdown-toggle"))
        )
        logger.info("Clicking language dropdown button...")
        dropdown_button.click()
        
        # Wait for dropdown items to be visible
        logger.info("Waiting for dropdown items...")
        dropdown_items = wait.until(
            EC.presence_of_all_elements_located((By.CLASS_NAME, "dropdown-item"))
        )

        # Filter and normalize language options
        logger.info("Available languages:")
        language_map = {
            'italiano': 'Italiano',
            'inglese': 'English',
            'francese': 'French',
            'tedesco': 'German',
            'spagnolo': 'Spanish',
            'portoghese': 'Portuguese',
            'olandese': 'Dutch',
            'russo': 'Russian',
            'polacco': 'Polish',
            'svedese': 'Swedish'
        }

        filtered_items = []
        normalized_idx = 1  # Start our normalized index from 1
        for item in dropdown_items:
            text = item.text.strip().lower()
            if text in ['italiano', 'inglese', 'english', 'italy']:
                normalized_text = language_map.get(text, text.title())
                filtered_items.append((normalized_idx, normalized_text, item))
                logger.info(f"{normalized_idx}: {normalized_text}")
                normalized_idx += 1  # Increment normalized index only for valid items

        # Ask the user to choose a language
        while True:
            user_choice = input("Enter the number corresponding to the language you want to select: ").strip()
            if user_choice.isdigit() and 1 <= int(user_choice) <= len(filtered_items):
                _, selected_language, selected_item = filtered_items[int(user_choice) - 1]
                logger.info(f"Selected language: {selected_language}")
                time.sleep(1)  # Small delay before clicking
                selected_item.click()
                logger.info("Language switch successful")
                return True
            else:
                logger.error("Invalid choice. Please enter a valid number.")
        
    except Exception as e:
        logger.error(f"Error while switching language: {str(e)}")
        return False


@app.route('/')
def home():
    return jsonify({
        "status": "online",
        "endpoints": {
            "/interact": "POST - Automate web interactions",
            "/health": "GET - Check system health"
        }
    })

@app.route('/health')
def health_check():
    return jsonify({"status": "healthy", "timestamp": time.time()})

@app.route('/interact', methods=['POST'])
def interact_with_page():
    try:
        if not request.is_json:
            return jsonify({"error": "Content type must be application/json"}), 400
            
        data = request.get_json()
        url = data.get("url")
        input_text = data.get("input_text")
        target_language = data.get("language", "it")  # Default to Italian if not specified

        if not url or not input_text:
            return jsonify({"error": "URL and input_text are required"}), 400

        driver = create_driver()
        try:
            logger.info(f"Navigating to URL: {url}")
            driver.get(url)

            wait = WebDriverWait(driver, Config.MAX_WAIT_TIME)
            
            logger.info("Looking for search input...")
            search_input = wait.until(
                EC.element_to_be_clickable((By.XPATH, '//*[@id="manasearch-input"]'))
            )
            logger.info("Search input found")
            
            logger.info("Interacting with search input...")
            search_input.click()
            search_input.clear()
            search_input.send_keys(input_text)
            logger.info(f"Entered text: {input_text}")
            
            logger.info("Waiting for dropdown...")
            time.sleep(3)
            
            logger.info("Attempting to click first dropdown item...")
            first_item_xpath = "/html/body/div[3]/div[1]/div[3]/div/div[1]/form/div/div[2]/div[4]/div[2]/ul/li[1]"
            
            try:
                first_item = wait.until(
                    EC.element_to_be_clickable((By.XPATH, first_item_xpath))
                )
                first_item.click()
            except Exception as e:
                logger.error(f"First click strategy failed: {str(e)}")
                try:
                    first_item = driver.find_element(By.XPATH, first_item_xpath)
                    driver.execute_script("arguments[0].click();", first_item)
                except Exception as e:
                    logger.error(f"JavaScript click strategy failed: {str(e)}")
                    try:
                        items = driver.find_elements(By.XPATH, "//ul/li")
                        logger.info(f"Found {len(items)} potential dropdown items")
                        for idx, item in enumerate(items):
                            logger.info(f"Item {idx}: {item.text}")
                    except Exception as e:
                        logger.error(f"Failed to list dropdown items: {str(e)}")
                    raise

            logger.info("First item clicked, waiting for prices...")
            time.sleep(3)
            
            # Switch language before proceeding
            logger.info(f"Attempting to switch language to {target_language}...")
            if not switch_language_interactive(driver, wait):
                logger.warning("Language switch failed, continuing with default language")
            driver.refresh()
            time.sleep(2)  # Wait for language change to take effect
            
            try:
                price_selector = ".price-box__price"
                price_elements = wait.until(
                    EC.presence_of_all_elements_located((By.CSS_SELECTOR, price_selector))
                )
                
                if len(price_elements) >= 2:
                    ct_min_price = price_elements[0].text
                    ct_market_price = price_elements[1].text
                    logger.info(f"Found min price: {ct_min_price}")
                    logger.info(f"Found market price: {ct_market_price}")
                else:
                    logger.error("Not enough price elements found.")
                    ct_min_price = "Not found"
                    ct_market_price = "Not found"
            except Exception as e:
                logger.error(f"Error fetching prices: {str(e)}")
                ct_min_price = "Not found"
                ct_market_price = "Not found"

            try:
                deal_selector = ".best-deal__currency"
                deal_element = wait.until(
                    EC.presence_of_element_located((By.CSS_SELECTOR, deal_selector))
                )
                
                deal_price = deal_element.find_element(By.XPATH, '..').text
                
                logger.info(f"Extracted price: {deal_price}")
            except Exception as e:
                logger.error(f"Failed to extract deal price: {str(e)}")
                deal_price = "Not found"

            result = {
                "current_url": driver.current_url,
                "page_title": driver.title,
                "ct_min_price": ct_min_price,
                "ct_market_price": ct_market_price,
                "best_market_price": deal_price,
                "timestamp": time.time()
            }
            
            logger.info(f"Final result: {result}")
            return jsonify({
                "status": "success",
                "message": "Operation completed",
                "result": result
            })
                
        finally:
            driver.quit()
            
    except Exception as e:
        logger.error(f"Error during page interaction: {str(e)}")
        return jsonify({"error": str(e)}), 500

def run_flask():
    """Run the Flask application."""
    app.run(host='0.0.0.0', port=5000, debug=False, use_reloader=False)

def send_test_request():
    """Send a test request to the API."""
    url = "http://127.0.0.1:5000/interact"
    headers = {
        "Content-Type": "application/json",
        "X-API-Key": os.environ.get('API_KEY', 'test-key')
    }
    data = {
        "url": "https://www.cardtrader.com/en/pokemon",
        "input_text": "Terapagos ex 170/142",
        "language": "en"  # Specify desired language
    }
    time.sleep(5)
    try:
        response = requests.post(url, headers=headers, json=data)
        logger.info(f"Test request response: {response.json()}")
    except requests.exceptions.RequestException as e:
        logger.error(f"Test request failed: {str(e)}")

if __name__ == '__main__':
    # Start Flask app in a separate thread
    flask_thread = threading.Thread(target=run_flask)
    flask_thread.daemon = True
    flask_thread.start()

    # Wait for Flask to start
    time.sleep(Config.FLASK_STARTUP_DELAY)
    
    # Send test request
    send_test_request()