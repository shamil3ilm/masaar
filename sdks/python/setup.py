"""
Masaar Python SDK Setup
"""

from setuptools import setup, find_packages

with open("README.md", "r", encoding="utf-8") as fh:
    long_description = fh.read()

setup(
    name="masaar",
    version="1.0.0",
    author="Masaar",
    author_email="support@masaar.sa",
    description="ZATCA-compliant e-invoicing API client for Python",
    long_description=long_description,
    long_description_content_type="text/markdown",
    url="https://github.com/masaar/masaar-python",
    packages=find_packages(),
    classifiers=[
        "Development Status :: 5 - Production/Stable",
        "Intended Audience :: Developers",
        "License :: OSI Approved :: MIT License",
        "Operating System :: OS Independent",
        "Programming Language :: Python :: 3",
        "Programming Language :: Python :: 3.7",
        "Programming Language :: Python :: 3.8",
        "Programming Language :: Python :: 3.9",
        "Programming Language :: Python :: 3.10",
        "Programming Language :: Python :: 3.11",
        "Programming Language :: Python :: 3.12",
        "Topic :: Software Development :: Libraries :: Python Modules",
    ],
    python_requires=">=3.7",
    install_requires=[
        "requests>=2.20.0",
    ],
    extras_require={
        "httpx": ["httpx>=0.20.0"],
        "dev": [
            "pytest>=6.0",
            "pytest-cov>=2.0",
            "black>=22.0",
            "mypy>=0.900",
        ],
    },
)
