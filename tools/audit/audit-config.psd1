@{
    # Report system public/ URL (must end with /public/)
    BaseUrl = 'https://toyama-mai.sakura.ne.jp/report-system/public/'

    # Test users for permission checks (password is asked at runtime, never stored here)
    Users = @(
        'k.ishihara@kk-artech.co.jp'
        'test_bucho@example.com'
        'test_kacho-dairi-a@example.com'
        'test_kacho-dairi-b@example.com'
        'test_shunin@example.com'
        'test_ippan-a@example.com'
        'test_ippan-b@example.com'
    )

    # Wait between HTTP requests (milliseconds)
    RequestDelayMs = 300

    # Max number of out-of-scope report IDs probed per user
    MaxProbePerUser = 5
}
