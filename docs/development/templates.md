# Twig Template Pattern

Templates should use OpenEMR's translation and sanitization filters:

```twig
{# templates/{feature}/view.html.twig #}

{% extends "base.html.twig" %}

{% block content %}
<div class="container">
    <h1>{{ 'Page Title'|xlt }}</h1>

    {% if error_message %}
        <div class="alert alert-danger">
            {{ error_message|text }}
        </div>
    {% endif %}

    <form method="post" action="{{ action_url|attr }}">
        <input type="hidden" name="csrf_token" value="{{ csrf_token|attr }}">

        <div class="form-group">
            <label>{{ 'Field Label'|xlt }}</label>
            <input type="text" name="field_name" class="form-control">
        </div>

        <button type="submit" class="btn btn-primary">
            {{ 'Submit'|xlt }}
        </button>
    </form>
</div>
{% endblock %}
```

## Twig Filter Reference

- `xlt` - Translate text
- `text` - Sanitize text for HTML output
- `attr` - Sanitize for HTML attributes
- `xlj` - Translate and JSON-encode for JavaScript

## Twig Global Variables

Available in all templates:

| Variable | Description |
|----------|-------------|
| `{{ webroot }}` | OpenEMR web root path (e.g., "/openemr") |
| `{{ assets_dir }}` | Static assets directory |
| `{{ srcdir }}` | Source directory |
| `{{ rootdir }}` | Root directory |
| `{{ assetVersion }}` | Asset cache version |
| `{{ session }}` | Session data |

## Static Assets - CRITICAL RULES

**NEVER use CDN links:**
- ~~`https://cdn.jsdelivr.net/npm/bootstrap@5.x/...`~~
- ~~`https://cdnjs.cloudflare.com/...`~~
- ~~`https://unpkg.com/...`~~
- ~~`https://maxcdn.bootstrapcdn.com/...`~~

**ALWAYS use OpenEMR's built-in assets:**
```twig
{# Bootstrap CSS #}
<link rel="stylesheet" href="{{ webroot }}/public/assets/bootstrap/dist/css/bootstrap.min.css">

{# Bootstrap JS #}
<script src="{{ webroot }}/public/assets/bootstrap/dist/js/bootstrap.bundle.min.js"></script>

{# jQuery (if needed) #}
<script src="{{ webroot }}/public/assets/jquery/dist/jquery.min.js"></script>
```

**Why no CDNs?**
- Security: No external dependencies
- Privacy: No tracking or data leakage
- Reliability: Works offline and in air-gapped environments
- Consistency: Matches OpenEMR's Bootstrap version

## Dialog/Iframe Templates

**For Dialog/Iframe Templates:**
- Do NOT use `openemr_header_setup()` function (not available in module Twig environment)
- Parent window provides jQuery and OpenEMR assets
- Use minimal inline styles for dialog content

**Example:**
```twig
<!DOCTYPE html>
<html>
<head>
    <title>{{ 'Dialog Title'|xlt }}</title>
    <style>
        body { padding: 15px; }
        .form-group { margin-bottom: 1rem; }
    </style>
</head>
<body>
    {# Dialog content #}
</body>
</html>
```

## Tab/Main Content Templates

**For Tab/Main Content Templates:**
- Set `X-Frame-Options: SAMEORIGIN` header to allow loading in OpenEMR tabs
- Include necessary assets via links (Bootstrap, etc.)
- Templates render in iframe context with OpenEMR's tab system
