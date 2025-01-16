import requests
import json

url = "http://127.0.0.1:5000/interact"
headers = {"Content-Type": "application/json"}
data = {
    "url": "https://example.com",
    "input_text": "breloom V"
}

response = requests.post(url, headers=headers, data=json.dumps(data))

print(response.json())
