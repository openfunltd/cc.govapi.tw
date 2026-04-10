<?php

putenv('ELASTIC_PASSWORD=');          // ES 密碼（必填）
putenv('ELASTIC_URL=');               // ES URL（必填）
putenv('ELASTIC_USER=');              // ES 使用者（必填）
putenv('ELASTIC_PREFIX=ccv1_');       // index 前綴，例如 ccv1_councilor
putenv('CCAPI_DOMAIN_POSTFIX=.cc.govapi.tw');  // 子網域後綴

// 匯入資料來源路徑（可指定絕對路徑，供不同主機使用）
putenv('IMPORT_COUNCIL_CSV=');        // 議會.csv 路徑，預設 {project}/議會.csv
putenv('IMPORT_TERM_CSV=');           // 屆.csv 路徑，預設 {project}/屆.csv
putenv('IMPORT_COUNCILOR_JSONL=');    // 議員.jsonl 路徑，預設 {project}/議員.jsonl
