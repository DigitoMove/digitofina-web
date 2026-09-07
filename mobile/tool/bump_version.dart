import 'dart:io';

void main() {
  final pubspecFile = File('pubspec.yaml');
  final contents = pubspecFile.readAsStringSync();
  final versionMatch = RegExp(
    r'^version:\s+(\d+)\.(\d+)\.(\d+)\+(\d+)$',
    multiLine: true,
  ).firstMatch(contents);

  if (versionMatch == null) {
    stderr.writeln('Could not find a valid version in pubspec.yaml.');
    exitCode = 1;
    return;
  }

  final buildNumber = int.parse(versionMatch.group(4)!) + 1;
  final minor = buildNumber ~/ 10;
  final patch = buildNumber % 10;
  final version = '1.$minor.$patch+$buildNumber';
  final updated = contents.replaceFirst(
    versionMatch.group(0)!,
    'version: $version',
  );
  pubspecFile.writeAsStringSync(updated);
  stdout.writeln(version);
}
