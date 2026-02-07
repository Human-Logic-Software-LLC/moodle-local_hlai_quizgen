# HL AI Quiz Generator - Flask Gateway

This is the Flask-based gateway server that powers the AI functionality for the HL AI Quiz Generator Moodle plugin. It acts as a secure intermediary between the Moodle plugin and Azure OpenAI.

## 🏗️ Architecture

```
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│   Moodle    │      │   Flask     │      │   Azure     │
│   Plugin    │─────▶│   Gateway   │─────▶│   OpenAI    │
│             │      │             │      │             │
└─────────────┘      └─────────────┘      └─────────────┘
    (Client)          (This Server)        (AI Provider)
```

**Why a Gateway?**
- ✅ **Protects AI prompts** - Proprietary prompt engineering stays on your server
- ✅ **Centralizes API keys** - Azure credentials never exposed to Moodle
- ✅ **Enables licensing** - Control access with gateway API keys
- ✅ **Supports multiple tenants** - One gateway can serve multiple Moodle instances
- ✅ **Safe for open source** - Plugin code can be public without exposing secrets

## 📋 Requirements

- **Python:** 3.9 or higher
- **Azure OpenAI:** Active subscription with deployed models
- **Operating System:** Linux, macOS, or Windows

## 🚀 Quick Start

### 1. Install Dependencies

```bash
cd gateway
pip install -r requirements.txt
```

### 2. Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` with your credentials:

```env
# Azure OpenAI Configuration
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com/
AZURE_DEPLOYMENT=gpt-4o-mini
AZURE_OPENAI_API_KEY=your_azure_key_here

# Gateway Security
GATEWAY_API_KEY=your_secure_key_here

# Server Configuration
PORT=8000
FLASK_DEBUG=False
```

**Generate a secure gateway key:**
```bash
openssl rand -hex 32
```

### 3. Run the Server

**Development:**
```bash
python app.py
```

**Production (using Gunicorn):**
```bash
gunicorn --bind 0.0.0.0:8000 --workers 4 app:app
```

### 4. Verify It's Running

```bash
curl http://localhost:8000/health
```

Expected response:
```json
{
  "status": "ok",
  "gateway_configured": true,
  "env_file_exists": true,
  "gateway_keys_count": 1
}
```

## 🔌 API Endpoints

### Authentication

All endpoints (except `/health`) require Bearer token authentication:

```http
Authorization: Bearer your_gateway_api_key
X-HL-Plugin: local_hlai_quizgen
```

### Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/health` | GET | Health check (no auth required) |
| `/analyze_topics` | POST | Extract topics from course content |
| `/generate_questions` | POST | Generate quiz questions for a topic |
| `/refine_question` | POST | Improve/refine an existing question |
| `/generate_distractors` | POST | Generate distractor options for multiple choice |

### Example: Analyze Topics

```bash
curl -X POST http://localhost:8000/analyze_topics \
  -H "Authorization: Bearer your_gateway_api_key" \
  -H "X-HL-Plugin: local_hlai_quizgen" \
  -H "Content-Type: application/json" \
  -d '{
    "operation": "analyze_topics",
    "quality": "balanced",
    "payload": {
      "content": "Python programming basics...",
      "courseid": 123
    }
  }'
```

## ⚙️ Configuration

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `AZURE_OPENAI_ENDPOINT` | Yes | - | Azure OpenAI endpoint URL |
| `AZURE_DEPLOYMENT` | Yes | - | Deployment name (e.g., gpt-4o-mini) |
| `AZURE_OPENAI_API_KEY` | Yes | - | Azure OpenAI API key |
| `AZURE_API_VERSION` | No | 2024-02-01 | Azure API version |
| `GATEWAY_API_KEY` | Yes | - | Primary gateway authentication key |
| `GATEWAY_API_KEYS` | No | - | Multiple keys (comma-separated) |
| `PORT` | No | 8000 | Server port |
| `FLASK_DEBUG` | No | False | Enable Flask debug mode |
| `SAFE_PROMPTS` | No | False | Use gentler language in prompts |
| `HL_GATEWAY_URL` | No | - | Override gateway URL for development |

### Multiple API Keys

To support multiple Moodle installations or customers:

```env
GATEWAY_API_KEYS=key1_for_site_a,key2_for_site_b,key3_for_customer_c
```

Each Moodle instance uses its own unique key for authentication.

## 🔒 Security

### Best Practices

1. **Never commit .env file** - It's in .gitignore for a reason
2. **Use strong gateway keys** - Minimum 32 characters, random
3. **Enable HTTPS in production** - Use reverse proxy (nginx/Apache)
4. **Rotate keys regularly** - Update both gateway and plugin settings
5. **Limit IP access** - Use firewall rules to restrict gateway access
6. **Monitor logs** - Watch for unauthorized access attempts

### Production Deployment

**Use a reverse proxy (nginx example):**

```nginx
server {
    listen 443 ssl;
    server_name ai.your-domain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**Run as systemd service:**

```ini
[Unit]
Description=HL AI Gateway
After=network.target

[Service]
Type=notify
User=www-data
WorkingDirectory=/path/to/gateway
Environment="PATH=/path/to/venv/bin"
ExecStart=/path/to/venv/bin/gunicorn --bind 127.0.0.1:8000 --workers 4 app:app
Restart=always

[Install]
WantedBy=multi-user.target
```

## 🐛 Troubleshooting

### Gateway not starting

**Check Python version:**
```bash
python --version  # Should be 3.9+
```

**Check dependencies:**
```bash
pip install -r requirements.txt --upgrade
```

**Check .env file:**
```bash
cat .env  # Verify all required variables are set
```

### Connection errors from Moodle

**Check if gateway is running:**
```bash
curl http://localhost:8000/health
```

**Check firewall:**
```bash
# Linux
sudo ufw allow 8000/tcp

# Windows
netsh advfirewall firewall add rule name="Flask Gateway" dir=in action=allow protocol=TCP localport=8000
```

**Check API key:**
- Ensure the key in Moodle plugin settings matches `GATEWAY_API_KEY` in `.env`

### Azure OpenAI errors

**Verify credentials:**
```bash
curl "$AZURE_OPENAI_ENDPOINT/openai/deployments/$AZURE_DEPLOYMENT/chat/completions?api-version=$AZURE_API_VERSION" \
  -H "api-key: $AZURE_OPENAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"messages":[{"role":"user","content":"test"}],"max_tokens":10}'
```

**Common issues:**
- Wrong endpoint URL
- Incorrect deployment name
- Expired or invalid API key
- Quota exceeded

## 📊 Monitoring

### Health Check

```bash
curl http://localhost:8000/health
```

Returns:
- `gateway_configured`: true if Azure credentials are set
- `env_file_exists`: true if .env file is present
- `gateway_keys_count`: number of configured API keys

### Logs

The gateway logs all requests to stdout. In production, redirect to a log file:

```bash
gunicorn app:app --access-logfile access.log --error-logfile error.log
```

## 🔄 Updating

```bash
cd gateway
git pull
pip install -r requirements.txt --upgrade
# Restart the service
sudo systemctl restart hlai-gateway  # if using systemd
```

## 📝 License

This gateway server is part of the HL AI Quiz Generator project.

Copyright 2025 Human Logic Software LLC

## 🆘 Support

- **Issues:** https://github.com/Nikhil-HL/HLAI_QUIZGEN/issues
- **Documentation:** See main repository README
