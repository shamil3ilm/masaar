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
    dependencies: [
        // CryptoKit's HMAC on platforms that have no CryptoKit (Linux).
        .package(url: "https://github.com/apple/swift-crypto.git", "3.0.0" ..< "5.0.0")
    ],
    targets: [
        .target(
            name: "Masaar",
            dependencies: [
                .product(name: "Crypto", package: "swift-crypto", condition: .when(platforms: [.linux]))
            ]
        )
    ]
)
