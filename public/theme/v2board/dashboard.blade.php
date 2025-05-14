<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta
      content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=0"
      name="viewport"
    />
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/index.css">
    <title>{{$title}}</title>
</head>
<body>
 {!! $theme_config['custom_html'] !!}
<div id="root"></div>
<script>
      window.settings = {
        version: '{{$version}}',
        logo: '{{$logo}}',
        title: '{{$title}}',
        description: '{{$description}}',
        theme_path: '/theme/{{$theme}}/assets/',
        theme:{
            via:'{{$theme_config['theme_via']}}',
            color:'{{$theme_config['theme_color']}}',
            default_i18n:'{{$theme_config['theme_default_i18n']}}',
            plan_custom_html:``,
            nodes_custom_html:``,
            ticket_custom_html:``,
        }
      }
</script>
<script type="module" crossorigin src="/theme/{{$theme}}/assets/index.js"></script>
</body>

</html>
