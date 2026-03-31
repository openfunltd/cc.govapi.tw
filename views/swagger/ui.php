<!-- HTML for Swagger UI -->
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <title>CCAPI - Swagger UI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.css" integrity="sha512-MvYROlKG3cDBPskMQgPmkNgZh85LIf68y7SZ34TIppaIHQz1M/3S/yYqzIfufdKDJjzB9Qu1BV63SZjimJkPvw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" type="text/css" href="/public/swagger-ui/index.css" />
  </head>
  <body>
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.js" integrity="sha512-mVvFSCxt0sK0FeL8C7n8BcHh10quzdwfxQbjRaw9pRdKNNep3YQusJS5e2/q4GYt4Ma5yWXSJraoQzXPgZd2EQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-standalone-preset.js" integrity="sha512-DgicCd4AI/d7/OdgaHqES3hA+xJ289Kb5NmMEegbN8w/Dxn5mvvqr9szOR6TQC+wjTTMeqPscKE4vj6bmAQn6g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
      window.onload = function() {
        window.ui = SwaggerUIBundle({
          url: "/swagger.yaml",
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: "StandaloneLayout"
        });
      };
    </script>
  </body>
</html>
