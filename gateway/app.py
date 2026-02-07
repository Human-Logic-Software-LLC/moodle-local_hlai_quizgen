from flask import Flask, jsonify, request
import json
import os
import re
import requests

try:
    from dotenv import load_dotenv
except Exception:
    load_dotenv = None

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
ENV_PATH = os.path.join(BASE_DIR, ".env")

def _load_env_fallback(path):
    if not os.path.exists(path):
        return
    try:
        with open(path, "r", encoding="utf-8") as handle:
            for raw_line in handle:
                line = raw_line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                key = key.strip()
                value = value.strip().strip('"').strip("'")
                if not key:
                    continue
                current = os.environ.get(key)
                if current is None or current == "":
                    os.environ[key] = value
    except Exception:
        pass

if load_dotenv:
    load_dotenv(ENV_PATH, override=True)
_load_env_fallback(ENV_PATH)

app = Flask(__name__)

# ===========================
# Configuration
# ===========================

AZURE_GRADING_ENDPOINT = os.getenv("AZURE_OPENAI_ENDPOINT", "").rstrip("/")
AZURE_GRADING_DEPLOYMENT = os.getenv("AZURE_DEPLOYMENT", "")
AZURE_GRADING_API_VERSION = os.getenv("AZURE_API_VERSION", "2024-02-15-preview")
AZURE_GRADING_API_KEY = os.getenv("AZURE_OPENAI_API_KEY", "")
SAFE_PROMPTS = os.getenv("SAFE_PROMPTS", "").strip().lower() in ("1", "true", "yes")

# ===========================
# Gateway Helpers
# ===========================

def _gateway_allowed_keys():
    """Return set of allowed gateway Bearer keys from env."""
    keys = set()
    raw_multi = os.getenv("GATEWAY_API_KEYS", "")
    for token in raw_multi.split(","):
        token = token.strip()
        if token:
            keys.add(token)
    single = os.getenv("GATEWAY_API_KEY", "").strip()
    if single:
        keys.add(single)
    return keys


def _gateway_extract_bearer_token():
    """Extract Bearer token from Authorization header."""
    auth = request.headers.get("Authorization", "").strip()
    if not auth.lower().startswith("bearer "):
        return ""
    return auth[7:].strip()


def _gateway_require_auth():
    """
    Validate gateway Bearer token.
    Returns None when valid, or (response, status_code) on failure.
    """
    allowed = _gateway_allowed_keys()
    if not allowed:
        return jsonify({"error": "Gateway authentication is not configured"}), 503

    token = _gateway_extract_bearer_token()
    if not token or token not in allowed:
        return jsonify({"error": "Unauthorized"}), 401
    return None


def _gateway_json_from_text(text):
    """Parse JSON content that may include markdown fences."""
    if text is None:
        return None
    if isinstance(text, dict):
        return text
    raw = str(text).strip()
    if not raw:
        return None
    raw = re.sub(r"^```(?:json)?\s*", "", raw, flags=re.IGNORECASE)
    raw = re.sub(r"\s*```$", "", raw)
    try:
        return json.loads(raw)
    except json.JSONDecodeError:
        match = re.search(r"\{[\s\S]*\}", raw)
        if not match:
            return None
        try:
            return json.loads(match.group(0))
        except json.JSONDecodeError:
            return None


def _build_rubric_prompt(question, submission, rubric_json, custom_instructions, answer_key):
    prompt_instructions = custom_instructions.strip() if custom_instructions else ""
    if answer_key:
        prompt_instructions = (prompt_instructions + "\n\nAnswer key / grading notes:\n" + answer_key).strip()

    prompt = "You are an expert educational grader.\n"
    prompt += "You must grade the student's work and return STRICT JSON.\n\n"
    prompt += f"Question / Assignment:\n{question}\n\n"
    prompt += f"Student Submission:\n{submission}\n\n"

    has_instructions = prompt_instructions != ""
    if has_instructions:
        prompt += "Educator Custom Instructions (highest priority - override any default guidance):\n"
        prompt += f"{prompt_instructions}\n\n"
        prompt += "You MUST follow every instruction above exactly. If any request conflicts with other guidance, the custom instructions win.\n\n"

    if rubric_json:
        prompt += f"Grading Rubric (JSON structure):\n{rubric_json}\n\n"
        prompt += "IMPORTANT INSTRUCTIONS:\n"
        prompt += "- You MUST evaluate the submission against each criterion defined in the rubric above.\n"
        prompt += "- For each criterion, assign a score based on the rubric levels provided.\n"
        prompt += "- Use the EXACT criterion names from the rubric in your response.\n"
        prompt += "- The total score should be the sum of all criterion scores.\n"
        prompt += "- The max_score should be the sum of all maximum scores from the rubric.\n"
        if has_instructions:
            prompt += "- Even if the rubric shows higher point values, NEVER exceed the limits or rules stated in the educator instructions above.\n"
    else:
        prompt += "No specific rubric provided. Grade on clarity, correctness, and completeness.\n"
        prompt += "Use reasonable maximum scores for each criterion.\n"

    prompt += "\nReturn JSON with at least these fields:\n"
    prompt += "{\n"
    prompt += '  "score": number,        // total numeric grade (sum of all criteria scores)\n'
    prompt += '  "max_score": number,    // total possible points (sum of all criteria max scores)\n'
    prompt += '  "feedback": string,     // overall teacher-style feedback\n'
    prompt += '  "criteria": [           // REQUIRED breakdown by criterion\n'
    prompt += '    {"name": "criterion name", "score": earned_points, "max_score": possible_points, "feedback": "specific feedback for this criterion"}\n'
    prompt += "  ]\n"
    prompt += "}\n"
    prompt += "\nDo NOT wrap your response in markdown code blocks. Return only raw JSON.\n"

    return prompt


def _build_semantic_similarity_prompt(answer_key, student_answer):
    prompt = "You are grading an answer against an answer key.\n"
    prompt += "Use a teacher-like, slightly lenient approach that focuses on meaning, reasoning, and correctness.\n"
    prompt += "Accept equivalent phrasing and synonyms; do not penalize minor wording differences.\n"
    prompt += "Do not award credit for incorrect or unrelated statements.\n"
    prompt += "For multi-part questions, treat each required part as a separate concept.\n"
    prompt += "Extract 3-8 key concepts from the key answer (core requirements only; do not include optional examples as required concepts).\n"
    prompt += "Label each concept as matched (fully covered), partially_matched (some evidence but incomplete/unclear), or missing.\n"
    prompt += "Apply this style for any subject or domain.\n"
    prompt += "Return only raw JSON with keys (no markdown, no extra text):\n"
    prompt += "- reasoning (brief explanation)\n"
    prompt += "- matched_concepts (array of short phrases fully covered)\n"
    prompt += "- partially_matched_concepts (array of short phrases partially covered)\n"
    prompt += "- missing_concepts (array of short phrases not covered)\n\n"
    prompt += "Examples (for style only; do not copy wording):\n"
    prompt += "Example 1\n"
    prompt += "Key: Uses include a main dish and an accompaniment/garnish.\n"
    prompt += "Student: Can be served as a main meal or as an accompaniment, e.g., pasta or rice.\n"
    prompt += "Output: {\"reasoning\":\"Student states both required uses with equivalent phrasing and a supporting example.\",\"matched_concepts\":[\"served as a main dish\",\"served as an accompaniment/garnish\"],\"partially_matched_concepts\":[],\"missing_concepts\":[]}\n\n"
    prompt += "Example 2\n"
    prompt += "Key: Provide five items: A, B, C, D, E.\n"
    prompt += "Student: A, B, D, E.\n"
    prompt += "Output: {\"reasoning\":\"Four of five required items are present; one is missing.\",\"matched_concepts\":[\"A\",\"B\",\"D\",\"E\"],\"partially_matched_concepts\":[],\"missing_concepts\":[\"C\"]}\n\n"
    prompt += "Example 3\n"
    prompt += "Key: Provide five items: A, B, C, D, E.\n"
    prompt += "Student: A, C.\n"
    prompt += "Output: {\"reasoning\":\"Two of five required items are present; three are missing.\",\"matched_concepts\":[\"A\",\"C\"],\"partially_matched_concepts\":[],\"missing_concepts\":[\"B\",\"D\",\"E\"]}\n\n"
    prompt += "Example 4\n"
    prompt += "Key: Usable product after 80% yield from 10 kg is 8000 g. Cost per 200 g portion is 2.50 AED.\n"
    prompt += "Student: Usable product is 8000 g. Cost per 200 g portion is 20 AED.\n"
    prompt += "Output: {\"reasoning\":\"Yield calculation is correct; portion cost is incorrect.\",\"matched_concepts\":[\"usable product = 8000 g\"],\"partially_matched_concepts\":[],\"missing_concepts\":[\"200 g portion cost = 2.50 AED\"]}\n\n"
    prompt += "Example 5\n"
    prompt += "Key: Unit tests catch defects early and prevent regressions.\n"
    prompt += "Student: They help find bugs early and stop changes from breaking existing behavior.\n"
    prompt += "Output: {\"reasoning\":\"Student captures both purposes with equivalent phrasing.\",\"matched_concepts\":[\"catch defects early\",\"prevent regressions\"],\"partially_matched_concepts\":[],\"missing_concepts\":[]}\n\n"
    prompt += "Example 6\n"
    prompt += "Key: Store potatoes in a dark place to prevent sprouting/greening.\n"
    prompt += "Student: To prevent photosynthesis.\n"
    prompt += "Output: {\"reasoning\":\"The answer does not address sprouting/greening.\",\"matched_concepts\":[],\"partially_matched_concepts\":[],\"missing_concepts\":[\"prevent sprouting/greening\"]}\n\n"
    prompt += "Answer key:\n" + answer_key + "\n\n"
    prompt += "Student answer:\n" + student_answer + "\n"
    return prompt


def _build_quiz_summary_prompt(payload):
    quiz_name = str(payload.get("quiz_name") or "Quiz")
    score = payload.get("score")
    max_score = payload.get("max_score")
    average_score = payload.get("average_score")
    average_similarity = payload.get("average_similarity")
    details = payload.get("details") or []

    score_line = "Overall score: n/a"
    if isinstance(score, (int, float)) and isinstance(max_score, (int, float)) and max_score > 0:
        score_line = f"Overall score: {score:.2f} / {max_score:.2f}"

    prompt = "You are a professional instructor writing feedback for a student.\n"
    prompt += "Write concise, teacher-style feedback in a supportive but direct tone.\n"
    prompt += "Provide exactly 3 areas of excellence, 3 areas for improvement, and an overall assessment paragraph.\n"
    prompt += "Each list item must start with a short label followed by ' - ' and a specific explanation.\n"
    prompt += "If performance criteria codes (e.g., PC 1.1) appear in the question text or expected answer, use them as labels.\n"
    prompt += "If no codes exist, use short topic labels (e.g., Q3, Technique, Concept).\n"
    prompt += "Apply the same style across any subject area.\n"
    prompt += "Overall feedback must be two short paragraphs (2-3 sentences each).\n"
    prompt += "Paragraph 1 should highlight specific strengths observed in the attempt (not generic praise).\n"
    prompt += "Paragraph 2 should give specific, actionable next steps tied to missing concepts or weak areas.\n"
    prompt += "Do not mention AI or the system.\n"
    prompt += "Return JSON only in this format:\n"
    prompt += "{\n"
    prompt += '  "overall_feedback": "text", \n'
    prompt += '  "strengths": ["...", "...", "..."],\n'
    prompt += '  "improvements": ["...", "...", "..."]\n'
    prompt += "}\n"
    prompt += "Style example (use tone and structure only; do not copy wording):\n"
    prompt += "Overall Assessment Feedback:\n"
    prompt += "\"Maria, you've demonstrated a strong foundation in core culinary principles, especially in identifying foundational elements and applying professional techniques. Your explanations show you can connect methods to outcomes, which is an important strength at this stage.\"\n"
    prompt += "\"To move from a good level to an outstanding level, add more technical depth in procedural answers and include specific examples that show why methods work. Focus on precision in terminology and step-by-step reasoning so your responses consistently cover all required points.\"\n"
    prompt += "Areas of excellence:\n"
    prompt += "- \"PC 1.1 - Strong understanding of foundational types and their applications, clearly linked to core principles.\"\n"
    prompt += "- \"PC 1.6 - Clear grasp of preparation and service procedures, including correct sequencing.\"\n"
    prompt += "- \"PC 1.5 - Accurate definition and examples, showing awareness of practical use in operations.\"\n"
    prompt += "Areas for improvement:\n"
    prompt += "- \"PC 1.3 - Add derivative examples and explain how technique changes affect outcomes.\"\n"
    prompt += "- \"PC 2.2 - Include more procedural details about handling, cleaning, and maintenance practices.\"\n"
    prompt += "- \"PC 2.4 - Use more precise technical terms and measurements where relevant.\"\n"
    prompt += "Non-culinary label example:\n"
    prompt += "- \"Q4 - Correctly applied the formula but did not explain the reasoning steps.\" \n"
    prompt += f"Quiz: {quiz_name}\n"
    prompt += f"{score_line}\n"
    if isinstance(average_score, (int, float)):
        prompt += f"Average score across questions: {average_score:.2f}%\n"
    if isinstance(average_similarity, (int, float)):
        prompt += f"Average essay semantic match: {average_similarity:.2f}%\n"
    prompt += "Question details (full attempt):\n"

    for index, detail in enumerate(details, start=1):
        question = detail.get("question") or ""
        question_type = detail.get("questiontype") or ""
        q_score = detail.get("score")
        q_max = detail.get("maxscore")
        similarity = detail.get("similarity")
        student_response = detail.get("student_response") or ""
        expected_answer = detail.get("expected_answer") or ""

        prompt += f"Q{index}: {question}\n"
        prompt += f"Type: {question_type}\n"
        if isinstance(q_score, (int, float)) and isinstance(q_max, (int, float)) and q_max > 0:
            prompt += f"Score: {q_score:.2f} / {q_max:.2f}\n"
        if isinstance(similarity, (int, float)):
            prompt += f"Essay semantic match: {similarity:.2f}%\n"
        prompt += f"Student response: {student_response}\n"
        prompt += f"Expected answer: {expected_answer}\n\n"

    return prompt


def _build_generate_key_prompt(question_name, question_text):
    question_name = (question_name or "").strip()
    question_text = (question_text or "").strip()
    prompt = "You are an instructor. Write a concise model answer key for the essay question below.\n"
    prompt += "Capture the key points a high-scoring response should include.\n"
    prompt += "Return plain text with bullet points if helpful. Keep it under 200 words.\n\n"
    prompt += f"Question: {question_name}\n"
    prompt += f"Question text: {question_text}\n"
    return prompt


def _soften_prompt(prompt):
    lines = prompt.splitlines()
    softened = []
    for line in lines:
        lower = line.lower()
        if "override any default guidance" in lower:
            continue
        if "do not wrap your response" in lower:
            softened.append("Return a valid JSON object without markdown code fences.")
            continue
        if "strict json" in lower:
            line = re.sub(r"STRICT JSON", "valid JSON", line, flags=re.IGNORECASE)
        if re.search(r"\\bmust\\b", line, flags=re.IGNORECASE):
            line = re.sub(r"\\bMUST\\b", "Please", line)
            line = re.sub(r"\\bmust\\b", "please", line)
        softened.append(line)
    softened.append("Please return only a valid JSON object.")
    return "\n".join(softened)


def _gateway_prompt_for_operation(operation, payload):
    """Build server-side prompt template by operation."""
    question = (payload.get("question") or "").strip()
    submission = (payload.get("submission") or payload.get("student_answer") or "").strip()
    answer_key = (payload.get("answer_key") or "").strip()
    custom_instructions = (payload.get("custom_instructions") or "").strip()
    rubric_json = payload.get("rubric_json")
    if isinstance(rubric_json, (dict, list)):
        rubric_json = json.dumps(rubric_json, ensure_ascii=False)
    rubric_json = (rubric_json or "").strip()

    if operation == "semantic_similarity":
        return _build_semantic_similarity_prompt(answer_key, submission)

    if operation == "quiz_summary":
        return _build_quiz_summary_prompt(payload)

    if operation in ("grade_rubric", "grade_text"):
        return _build_rubric_prompt(
            question=question,
            submission=submission,
            rubric_json=rubric_json,
            custom_instructions=custom_instructions,
            answer_key=answer_key,
        )

    return None


def call_azure_grading(prompt, temperature=0.7, top_p=0.9, max_tokens=2000):
    """
    Call Azure OpenAI for quiz grading and evaluation

    Returns:
        tuple: (result_dict, error_string)
    """
    if not (AZURE_GRADING_ENDPOINT and AZURE_GRADING_DEPLOYMENT and AZURE_GRADING_API_KEY):
        return None, "Azure grading config missing (AZURE_ENDPOINT, AZURE_DEPLOYMENT, AZURE_API_KEY)"

    url = f"{AZURE_GRADING_ENDPOINT}/openai/deployments/{AZURE_GRADING_DEPLOYMENT}/chat/completions"
    params = {"api-version": AZURE_GRADING_API_VERSION}
    payload = {
        "messages": [{"role": "user", "content": prompt}],
        "temperature": temperature,
        "top_p": top_p,
        "max_tokens": max_tokens,
    }
    headers = {"Content-Type": "application/json", "api-key": AZURE_GRADING_API_KEY}

    try:
        resp = requests.post(
            url,
            params=params,
            headers=headers,
            json=payload,
            timeout=60,
            proxies={"http": None, "https": None},
        )
    except Exception as exc:
        return None, f"Azure call failed: {exc}"

    if not resp.ok:
        return None, f"Azure call failed: {resp.status_code} {resp.text}"

    data = resp.json()
    content = data.get("choices", [{}])[0].get("message", {}).get("content", "")
    usage = data.get("usage", {}) or {}

    return {
        "content": content,
        "model": data.get("model") or AZURE_GRADING_DEPLOYMENT,
        "tokens": {
            "prompt": usage.get("prompt_tokens", 0),
            "completion": usage.get("completion_tokens", 0),
            "total": usage.get("total_tokens", 0),
        },
        "raw": data,
    }, None

# ===========================
# Routes
# ===========================

@app.route('/health', methods=['GET'])
def health():
    allowed = _gateway_allowed_keys()
    return jsonify({
        "status": "ok",
        "gateway_configured": bool(allowed),
        "gateway_keys_count": len(allowed),
        "env_file_exists": os.path.exists(ENV_PATH),
    })


@app.route('/grade', methods=['POST'])
def gateway_grade():
    """
    Commercial grading gateway endpoint for Moodle plugin.
    Accepts structured payload and keeps prompt/model internals server-side.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    operation = str(body.get("operation") or "").strip()
    quality = str(body.get("quality") or "balanced").strip().lower()
    payload = body.get("payload") or {}

    if operation == "" or not isinstance(payload, dict):
        return jsonify({"error": "Invalid request payload"}), 400

    prompt = _gateway_prompt_for_operation(operation, payload)
    if not prompt:
        return jsonify({"error": "Unsupported operation"}), 400

    quality_temp_map = {"fast": 0.2, "balanced": 0.4, "best": 0.6}
    temperature = quality_temp_map.get(quality, 0.4)

    prompt_to_send = _soften_prompt(prompt) if SAFE_PROMPTS else prompt
    llm_result, llm_error = call_azure_grading(
        prompt_to_send,
        temperature=temperature,
        top_p=0.9,
        max_tokens=1800,
    )
    if llm_error and ("content_filter" in llm_error.lower() or "responsibleaipolicyviolation" in llm_error.lower()):
        if not SAFE_PROMPTS:
            retry_prompt = _soften_prompt(prompt)
            llm_result, llm_error = call_azure_grading(
                retry_prompt,
                temperature=temperature,
                top_p=0.9,
                max_tokens=1800,
            )
    if llm_error:
        if os.getenv("DEBUG_AZURE_ERRORS", "").strip().lower() in ("1", "true", "yes"):
            return jsonify({"error": "AI grading is temporarily unavailable", "details": llm_error}), 502
        return jsonify({"error": "AI grading is temporarily unavailable"}), 502

    parsed = _gateway_json_from_text(llm_result.get("content"))
    if not isinstance(parsed, dict):
        return jsonify({"error": "AI response parsing failed"}), 502

    if operation == "semantic_similarity":
        normalized = {
            "matched_concepts": parsed.get("matched_concepts", []),
            "partially_matched_concepts": parsed.get("partially_matched_concepts", []),
            "missing_concepts": parsed.get("missing_concepts", []),
            "reasoning": parsed.get("reasoning", ""),
        }
        provider = "semantic"
    elif operation == "quiz_summary":
        normalized = {
            "overall_feedback": parsed.get("overall_feedback", ""),
            "strengths": parsed.get("strengths", []),
            "improvements": parsed.get("improvements", []),
        }
        provider = "summary"
    elif operation == "grade_rubric":
        normalized = {
            "criteria": parsed.get("criteria", []),
            "feedback": parsed.get("feedback", ""),
        }
        provider = "rubric"
    else:
        normalized = {
            "score": parsed.get("score", 0),
            "max_score": parsed.get("max_score", 100),
            "feedback": parsed.get("feedback", ""),
            "criteria": parsed.get("criteria", []),
        }
        provider = "text"

    return jsonify({
        "provider": f"hub:{provider}",
        "content": normalized,
        "usage": llm_result.get("tokens", {}),
    }), 200


@app.route('/generate_key', methods=['POST'])
def generate_key():
    """
    Generate a model answer key for an essay question.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    question_name = body.get("question") or body.get("question_name") or ""
    question_text = body.get("question_text") or body.get("questionText") or ""

    if not str(question_name).strip() and not str(question_text).strip():
        return jsonify({"error": "Missing question or question_text"}), 400

    prompt = _build_generate_key_prompt(question_name, question_text)
    llm_result, llm_error = call_azure_grading(
        prompt,
        temperature=0.2,
        top_p=0.9,
        max_tokens=500,
    )
    if llm_error:
        return jsonify({"error": "AI key generation is temporarily unavailable"}), 502

    content = (llm_result.get("content") or "").strip()
    content = re.sub(r"^```[a-z]*\s*", "", content, flags=re.IGNORECASE)
    content = re.sub(r"\s*```$", "", content)

    return jsonify({
        "provider": "hub:generate_key",
        "content": content,
        "usage": llm_result.get("tokens", {}),
    }), 200


# ===========================
# Quiz Generation Endpoints
# ===========================

@app.route('/analyze_topics', methods=['POST'])
def analyze_topics():
    """
    Analyze course content and extract learning topics.
    Commercial endpoint for local_hlai_quizgen plugin.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    quality = str(body.get("quality") or "best").strip().lower()
    payload = body.get("payload") or {}

    content = payload.get('content', '').strip()
    if not content:
        return jsonify({"error": "Missing content"}), 400

    # Build prompt for topic extraction
    prompt = _build_topic_extraction_prompt(content)

    quality_temp_map = {"fast": 0.2, "balanced": 0.3, "best": 0.3}
    temperature = quality_temp_map.get(quality, 0.3)

    llm_result, llm_error = call_azure_grading(
        prompt,
        temperature=temperature,
        top_p=0.9,
        max_tokens=8000,
    )

    if llm_error:
        return jsonify({"error": "AI topic analysis is temporarily unavailable"}), 502

    parsed = _gateway_json_from_text(llm_result.get("content"))
    if not isinstance(parsed, dict) or 'topics' not in parsed:
        return jsonify({"error": "AI response parsing failed"}), 502

    return jsonify({
        "provider": "hub:analyze_topics",
        "content": {
            "topics": parsed.get('topics', [])
        },
        "usage": llm_result.get("tokens", {}),
    }), 200


@app.route('/generate_questions', methods=['POST'])
def generate_questions():
    """
    Generate quiz questions for a specific topic.
    Commercial endpoint for local_hlai_quizgen plugin.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    quality = str(body.get("quality") or "balanced").strip().lower()
    payload = body.get("payload") or {}

    # Extract payload data
    topic_title = payload.get('topic_title', '').strip()
    topic_content = payload.get('topic_content', '').strip()
    question_types = payload.get('question_types', ['multichoice'])
    difficulty_dist = payload.get('difficulty_distribution', {'easy': 20, 'medium': 60, 'hard': 20})
    blooms_dist = payload.get('blooms_distribution', {
        'remember': 20, 'understand': 25, 'apply': 25,
        'analyze': 15, 'evaluate': 10, 'create': 5
    })
    num_questions = payload.get('num_questions', len(question_types))
    existing_questions = payload.get('existing_questions', [])
    is_regeneration = payload.get('is_regeneration', False)
    old_question_text = payload.get('old_question_text', '')

    if not topic_title:
        return jsonify({"error": "Missing topic_title"}), 400

    # Build prompt for question generation
    prompt = _build_question_generation_prompt(
        topic_title, topic_content, question_types, difficulty_dist,
        blooms_dist, existing_questions, is_regeneration, old_question_text
    )

    quality_temp_map = {"fast": 0.5, "balanced": 0.7, "best": 0.7}
    temperature = quality_temp_map.get(quality, 0.7)
    if is_regeneration:
        temperature = 0.9  # Higher temperature for regeneration

    llm_result, llm_error = call_azure_grading(
        prompt,
        temperature=temperature,
        top_p=0.9,
        max_tokens=3000,
    )

    if llm_error:
        return jsonify({"error": "AI question generation is temporarily unavailable"}), 502

    parsed = _gateway_json_from_text(llm_result.get("content"))
    if not isinstance(parsed, list):
        return jsonify({"error": "AI response parsing failed"}), 502

    # Parse questions from response
    questions = []
    for i, qdata in enumerate(parsed[:num_questions]):
        if isinstance(qdata, dict):
            questions.append({
                'questiontext': qdata.get('questiontext', ''),
                'questiontype': question_types[i] if i < len(question_types) else 'multichoice',
                'difficulty': qdata.get('difficulty', 'medium'),
                'blooms_level': qdata.get('blooms_level', 'understand'),
                'answers': qdata.get('answers', []),
                'generalfeedback': str(qdata.get('generalfeedback', '')),
                'ai_reasoning': qdata.get('ai_reasoning', ''),
            })

    return jsonify({
        "provider": "hub:generate_questions",
        "content": {
            "questions": questions,
            "tokens": llm_result.get("tokens", {})
        },
        "usage": llm_result.get("tokens", {}),
    }), 200


@app.route('/refine_question', methods=['POST'])
def refine_question():
    """
    Refine or regenerate an existing question.
    Commercial endpoint for local_hlai_quizgen plugin.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    quality = str(body.get("quality") or "balanced").strip().lower()
    payload = body.get("payload") or {}

    existing_question = payload.get('existing_question', '').strip()
    topic_title = payload.get('topic_title', '').strip()
    topic_content = payload.get('topic_content', '').strip()
    question_type = payload.get('question_type', 'multichoice')
    difficulty = payload.get('difficulty', 'medium')

    if not existing_question or not topic_title:
        return jsonify({"error": "Missing existing_question or topic_title"}), 400

    # Build prompt for question refinement
    prompt = _build_question_refinement_prompt(
        existing_question, topic_title, topic_content, question_type, difficulty
    )

    quality_temp_map = {"fast": 0.7, "balanced": 0.9, "best": 0.9}
    temperature = quality_temp_map.get(quality, 0.9)

    llm_result, llm_error = call_azure_grading(
        prompt,
        temperature=temperature,
        top_p=0.9,
        max_tokens=1500,
    )

    if llm_error:
        return jsonify({"error": "AI question refinement is temporarily unavailable"}), 502

    parsed = _gateway_json_from_text(llm_result.get("content"))
    if not isinstance(parsed, dict):
        return jsonify({"error": "AI response parsing failed"}), 502

    return jsonify({
        "provider": "hub:refine_question",
        "content": {
            "question": {
                'questiontext': parsed.get('questiontext', ''),
                'questiontype': question_type,
                'difficulty': parsed.get('difficulty', difficulty),
                'blooms_level': parsed.get('blooms_level', 'understand'),
                'answers': parsed.get('answers', []),
                'generalfeedback': str(parsed.get('generalfeedback', '')),
            }
        },
        "usage": llm_result.get("tokens", {}),
    }), 200


@app.route('/generate_distractors', methods=['POST'])
def generate_distractors():
    """
    Generate plausible wrong answers (distractors) for multiple choice questions.
    Commercial endpoint for local_hlai_quizgen plugin.
    """
    auth_error = _gateway_require_auth()
    if auth_error:
        return auth_error

    body = request.get_json(silent=True) or {}
    quality = str(body.get("quality") or "balanced").strip().lower()
    payload = body.get("payload") or {}

    question_text = payload.get('question_text', '').strip()
    correct_answer = payload.get('correct_answer', '').strip()
    difficulty = payload.get('difficulty', 'medium')
    num_distractors = payload.get('num_distractors', 3)

    if not question_text or not correct_answer:
        return jsonify({"error": "Missing question_text or correct_answer"}), 400

    # Build prompt for distractor generation
    prompt = _build_distractor_generation_prompt(
        question_text, correct_answer, difficulty, num_distractors
    )

    quality_temp_map = {"fast": 0.5, "balanced": 0.7, "best": 0.7}
    temperature = quality_temp_map.get(quality, 0.7)

    llm_result, llm_error = call_azure_grading(
        prompt,
        temperature=temperature,
        top_p=0.9,
        max_tokens=800,
    )

    if llm_error:
        return jsonify({"error": "AI distractor generation is temporarily unavailable"}), 502

    parsed = _gateway_json_from_text(llm_result.get("content"))
    if not isinstance(parsed, dict):
        return jsonify({"error": "AI response parsing failed"}), 502

    return jsonify({
        "provider": "hub:generate_distractors",
        "content": {
            "distractors": parsed.get('distractors', [])
        },
        "usage": llm_result.get("tokens", {}),
    }), 200


# ===========================
# Prompt Building Functions (Server-side only)
# ===========================

def _build_topic_extraction_prompt(content: str) -> str:
    """Build prompt for topic extraction from content."""
    # Limit content size
    max_length = 150000
    if len(content) > max_length:
        content = content[:max_length]

    prompt = f"""You are an educational content analyzer. Extract meaningful topics from the content structure.

CONTENT TO ANALYZE:
---
{content}
---

INSTRUCTIONS:
1. Look for structural elements in the content:
   - Topic markers: "=== TOPIC: [Name] ([Type]) ===" - use the [Name] as the topic title
   - Activity Name fields after topic markers
   - Headings (# Heading, ## Subheading in markdown)
   - Chapter markers (Chapter 1:, Module 2:, Week 3:)

2. CRITICAL NAMING RULES:
   - When you see "=== TOPIC: [Activity Name] ([Type]) ===" markers, use the EXACT [Activity Name] as the topic title
   - Example: "=== TOPIC: Introduction to Python (Lesson) ===" → topic title = "Introduction to Python"
   - NEVER use generic names like "SCORM", "Lesson", "Forum", "Page" as topic titles
   - NEVER use the activity TYPE as the title - use the actual NAME
   - The topic title should describe WHAT the content teaches, not what format it's in
   - DO NOT include prefixes like "SCORM:", "SECTION:", "COURSE:", "LESSON:" in topic titles
   - BAD: "SCORM: Control Safety Hazards" - GOOD: "Control Safety Hazards"
   - BAD: "SECTION: Valves: Introduction" - GOOD: "Valves: Introduction" or just "Introduction to Valves"

3. Extract ALL topics/sections found - do NOT limit the number
4. For each topic, note key concepts mentioned in that section
5. Focus on the EDUCATIONAL CONTENT, not the delivery format

EXCLUSION RULES - DO NOT include as topics:
- Pure numbers (1, 2, 3, 4.5, etc.)
- Generic module types alone (SCORM, Lesson, Forum, Page, Book, Resource)
- Exercise markers without context (Exercise 1, Practice, Worksheet)
- Navigation elements (Next, Previous, Home, Back)
- Empty or placeholder content

ONLY extract topics that represent actual subject matter or educational content.

FORMAT YOUR RESPONSE AS JSON:
{{
  "topics": [
    {{
      "title": "The actual activity name or content title (NOT 'SCORM' or 'Lesson')",
      "description": "Main concepts covered in this section",
      "level": 1,
      "subtopics": [
        {{
          "title": "Subsection or key concept",
          "description": "Brief description",
          "level": 2
        }}
      ],
      "learning_objectives": [
        "What students learn from this section"
      ],
      "content_excerpt": "First 300 chars from this section"
    }}
  ]
}}

CRITICAL RULES:
- Use EXACT activity names from "=== TOPIC: [Name] ===" markers as topic titles
- NEVER use "SCORM", "Lesson", "Forum" etc. alone as topic titles - these are formats, not topics
- NEVER prefix titles with "SCORM:", "SECTION:", "COURSE:", "LESSON:" etc.
- If you see "Activity Name: XYZ" in the content, "XYZ" should be the topic title (without any prefix)
- Questions will be generated ABOUT the topic title, so it must be descriptive of the SUBJECT MATTER
- Return ONLY valid JSON, no additional text"""

    return prompt


def _build_question_generation_prompt(topic_title: str, topic_content: str, question_types: list,
                                       difficulty_dist: dict, blooms_dist: dict, existing_questions: list,
                                       is_regeneration: bool, old_question_text: str) -> str:
    """Build prompt for question generation."""
    # Limit content size
    max_content_length = 5000
    if len(topic_content) > max_content_length:
        topic_content = topic_content[:max_content_length] + "..."

    prompt = f"Topic: {topic_title}\nContent:\n{topic_content}\n\n"

    # Add regeneration instructions if applicable
    if is_regeneration and old_question_text:
        clean_old = old_question_text[:500]
        prompt += f"""**REGENERATION REQUEST**
You MUST generate a COMPLETELY DIFFERENT question. DO NOT use the same wording, structure, or approach.
OLD QUESTION TO REPLACE (DO NOT REGENERATE THIS):
"{clean_old}"

Generate a NEW question that tests the SAME topic but uses:
- Different wording and phrasing
- Different angle or perspective
- Different examples or scenarios

"""
    # Add deduplication instructions
    elif existing_questions:
        prompt += "AVOID similar to:\n"
        for eq in existing_questions[-10:]:  # Last 10 questions
            prompt += f"- {eq}\n"
        prompt += "\n"

    # Add difficulty guidelines
    prompt += """DIFFICULTY LEVELS:
EASY: Basic recall/definitions. MCQ distractors clearly wrong.
MEDIUM: Apply/analyze concepts. MCQ distractors plausible but distinguishable.
HARD: Critical thinking/scenarios. All MCQ options seem reasonable.

"""

    # Add question specifications
    for i, qtype in enumerate(question_types):
        num = i + 1
        type_name = {
            'multichoice': 'Multiple Choice',
            'truefalse': 'True/False',
            'shortanswer': 'Short Answer (question MUST include a hint, answer MUST be ONE WORD only)',
            'essay': 'Essay (include model answer and grading rubric in generalfeedback)',
            'matching': 'Matching',
        }.get(qtype, 'Multiple Choice')

        # Select difficulty for this question
        difficulty = _select_from_distribution(difficulty_dist)
        blooms = _select_from_distribution(blooms_dist)

        prompt += f"{num}. {type_name} (Difficulty: {difficulty}, Bloom's: {blooms})\n"

    prompt += """\n
Return a JSON array with the following structure:
[{
  "questiontext": "...",
  "questiontype": "...",
  "difficulty": "...",
  "blooms_level": "...",
  "answers": [{"text": "...", "fraction": 1 or 0, "feedback": "..."}],
  "generalfeedback": "...",
  "ai_reasoning": "..."
}]

CRITICAL: Return ONLY valid JSON array, no explanatory text, no markdown code blocks, no additional content."""

    if 'essay' in question_types:
        prompt += "\n\nNote for ESSAY questions: generalfeedback MUST include model answer (150+ words), key points, and grading criteria."

    return prompt


def _build_question_refinement_prompt(existing_question: str, topic_title: str, topic_content: str,
                                       question_type: str, difficulty: str) -> str:
    """Build prompt for question refinement."""
    prompt = f"""You are an expert educator. Regenerate the following question to make it better.

Topic: {topic_title}
Question Type: {question_type}
Difficulty: {difficulty}

EXISTING QUESTION:
{existing_question}

INSTRUCTIONS:
- Generate a COMPLETELY DIFFERENT question on the same topic
- Use different wording, examples, and approach
- Maintain the same difficulty level and question type
- Ensure high quality and clarity

Return JSON format:
{{
  "questiontext": "...",
  "questiontype": "{question_type}",
  "difficulty": "{difficulty}",
  "blooms_level": "...",
  "answers": [
    {{"text": "...", "fraction": 1, "feedback": "..."}}
  ],
  "generalfeedback": "..."
}}

Return ONLY valid JSON, no additional text."""

    return prompt


def _build_distractor_generation_prompt(question_text: str, correct_answer: str,
                                         difficulty: str, num_distractors: int) -> str:
    """Build prompt for distractor generation."""
    prompt = f"""Generate {num_distractors} plausible wrong answers (distractors) for this multiple choice question.

Question: {question_text}
Correct Answer: {correct_answer}
Difficulty: {difficulty}

INSTRUCTIONS:
- Generate distractors that are PLAUSIBLE but INCORRECT
- For {difficulty} difficulty:
  - EASY: Distractors should be clearly wrong to knowledgeable students
  - MEDIUM: Distractors should be somewhat plausible
  - HARD: Distractors should be very plausible and require careful thought
- Each distractor should represent a common misconception or error
- Provide reasoning for why each distractor is plausible

Return JSON format:
{{
  "distractors": [
    {{"text": "...", "reasoning": "Why this is plausible but wrong"}},
    ...
  ]
}}

Return ONLY valid JSON, no additional text."""

    return prompt


def _select_from_distribution(distribution: dict) -> str:
    """Select an item randomly based on distribution percentages."""
    import random
    rand = random.randint(1, 100)
    cumulative = 0
    for level, percentage in distribution.items():
        cumulative += percentage
        if rand <= cumulative:
            return level
    return list(distribution.keys())[0]  # Fallback


if __name__ == '__main__':
    port = int(os.environ.get('PORT', 8000))
    debug = os.environ.get('FLASK_DEBUG', 'False').lower() == 'true'
    app.run(host='0.0.0.0', port=port, debug=debug)
