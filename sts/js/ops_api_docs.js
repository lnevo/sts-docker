    window.onload = function () {
      SwaggerUIBundle({
        url: 'operational_steps_api.openapi.yaml',
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'StandaloneLayout',
        tryItOutEnabled: true,
      });
    };
