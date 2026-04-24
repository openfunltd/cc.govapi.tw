<!-- HTML for Swagger UI -->
<!DOCTYPE html>
<html lang="zh-tw">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CCAPI — API 文件</title>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" integrity="sha512-jnSuA4Ss2PkkikSOLtYs8BlYIeeIK1h99ty4YfvRPAlzr377vr3CXDb7sb7eEEBYjDtcYj+AjBH3FLv5uSJuXg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js" integrity="sha512-7Pi/otdlbbCR+LnW+F7PwFcSDJOuUJB3OxtEHbg4vSMvzvJjde4Po1v4BR9Gdc9aXNUNFVUY+SK51wWT8WF0Gg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.css" integrity="sha512-MvYROlKG3cDBPskMQgPmkNgZh85LIf68y7SZ34TIppaIHQz1M/3S/yYqzIfufdKDJjzB9Qu1BV63SZjimJkPvw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
      body { font-family: 'Noto Sans TC', sans-serif; margin: 0; }
      .swagger-ui { font-family: inherit; }
    </style>
  </head>
  <body>
<?php
  $active = 'swagger';
  $cc_code = $_SERVER['CCAPI_COUNCIL_CODE'] ?? 'all';
  $council_name = CouncilHelper::getName($cc_code);
  include(__DIR__ . '/../nav/top.php');
?>
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
