// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "Masaar",
    platforms: [
        .macOS(.v12),
        .iOS(.v15),
        .tvOS(.v15),
        .watchOS(.v8)
    ],
    products: [
        .library(name: "Masaar", targets: ["Masaar"])
    ],
    targets: [
        .target(name: "Masaar", dependencies: []),
        .testTarget(name: "MasaarTests", dependencies: ["Masaar"])
    ]
)
