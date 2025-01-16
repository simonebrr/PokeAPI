import requests

# Configurazione dell'endpoint e dei parametri
url = "https://api.cardtrader.com/api/v2/marketplace/products"
expansion_id = "1468"  # Sostituisci con il valore appropriato
params = {"expansion_id": expansion_id}

# Token di autenticazione
auth_token = "eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJjYXJkdHJhZGVyLXByb2R1Y3Rpb24iLCJzdWIiOiJhcHA6MTM1NTkiLCJhdWQiOiJhcHA6MTM1NTkiLCJleHAiOjQ4OTI3MTI2NjIsImp0aSI6IjUwODZiZmJkLTFkMTktNDJlMi04MjUxLThkZDYzYTdiYzBmMiIsImlhdCI6MTczNzAzOTA2MiwibmFtZSI6IlByZXRlMjAwMiBBcHAgMjAyNTAxMTYxNTUxMDIifQ.TOh5cOT1cJ7yvRsNlkWatvsO8gXtjgELUNpPnKF51VjbxTeOOyrOiVRs82f6_C3-NK6nrQb5yG8Nnj58mDfUaV3yT5srVS5XRV9GtJu2DjvZHxHLi80tFsfjaRfoBAcZ4txNeF9VGPiQwKOSHLAF0jywCyiy9FG219Gj3Vko0dawoP68WozdpjmY8_ame-AA2NRvHrK71n_dA9K0Vkv8ycn4CiJYoNWzAnKdY1RVkDxW9VcYJc250KLbeA1lrV6zcV-ylWpY-Xx-u_Xc7aYbWUaEf3FZCNz7Bqb7Gn7fv24wjP_S9lmcANdPYyZQTNNGau_0pti6awymwtxCbaaFGg"  # Sostituisci con il tuo token

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
