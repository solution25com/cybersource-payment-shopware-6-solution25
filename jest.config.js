module.exports = {
    transform: {
      '^.+\\.js?$': 'babel-jest',
    },
    testEnvironment: 'jsdom',
    setupFilesAfterEnv: ['./jest.setup.js'],
    moduleFileExtensions: ['js', 'html'],
    moduleNameMapper: {
        '^src/(.*)$': '<rootDir>/src/$1',
  },
  roots: [
    '<rootDir>/src/Resources/app/storefront'
  ]
};
