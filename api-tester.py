import os
import requests

# Configurazione dell'endpoint e dei parametri
url = "https://api.cardtrader.com/api/v2/marketplace/products"
expansion_id = "1468"  # Sostituisci con il valore appropriato
params = {"expansion_id": expansion_id}

# Token di autenticazione
auth_token = os.environ["CARDTRADER_TOKEN"]  # export CARDTRADER_TOKEN=... prima di eseguire

# Headers
headers = {
    "Authorization": f"Bearer {auth_token}"
}

# Effettua la richiesta GET
try:
    response = requests.get(url, headers=headers, params=params)
    response.raise_for_status()  # Verifica se ci sono errori HTTP

    # Stampa il risultato
    print("Response status code:", response.status_code)
    print("Response JSON:", response.json())
except requests.exceptions.RequestException as e:
    print(f"Request failed: {e}")
