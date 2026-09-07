class ApiConfig {
  const ApiConfig({required this.baseUrl});

  factory ApiConfig.fromEnvironment() {
    const configuredUrl = String.fromEnvironment('API_BASE_URL');

    return ApiConfig(
      baseUrl: configuredUrl.isEmpty
          ? 'https://digitofina.digitomove.com/api/v1'
          : configuredUrl,
    );
  }

  final String baseUrl;
}
