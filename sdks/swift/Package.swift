// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "CompliPay",
    platforms: [
        .macOS(.v12),
        .iOS(.v15),
        .tvOS(.v15),
        .watchOS(.v8)
    ],
    products: [
        .library(name: "CompliPay", targets: ["CompliPay"])
    ],
    targets: [
        .target(name: "CompliPay", dependencies: []),
        .testTarget(name: "CompliPayTests", dependencies: ["CompliPay"])
    ]
)
